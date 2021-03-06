<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */

namespace Spiral\Validation;

use Interop\Container\ContainerInterface;
use Psr\Log\LoggerAwareInterface;
use Spiral\Core\Component;
use Spiral\Core\Exceptions\ScopeException;
use Spiral\Core\Traits\SaturateTrait;
use Spiral\Debug\Traits\LoggerTrait;
use Spiral\Models\AccessorInterface;
use Spiral\Models\EntityInterface;
use Spiral\Translator\Traits\TranslatorTrait;
use Spiral\Validation\Configs\ValidatorConfig;
use Spiral\Validation\Exceptions\ValidationException;

/**
 * Validator is default implementation of ValidatorInterface. Class support functional rules with
 * user parameters. In addition, part of validation rules moved into validation checkers used to
 * simplify adding new rules, checkers are resolved using container and can be rebinded in
 * application.
 *
 * Examples:
 *      "status" => [
 *           ["notEmpty"],
 *           ["string::shorter", 10, "error" => "Your string is too short."],
 *           [["MyClass", "myMethod"], "error" => "Custom validation failed."]
 *      ],
 *      "email" => [
 *           ["notEmpty", "error" => "Please enter your email address."],
 *           ["email", "error" => "Email is not valid."]
 *      ],
 *      "pin" => [
 *           ["string::regexp", "/[0-9]{5}/", "error" => "Invalid pin format, if you don't know your
 *                                                           pin, please skip this field."]
 *      ],
 *      "flag" => ["notEmpty", "boolean"]
 *
 * In cases where you don't need custom message or check parameters you can use simplified
 * rule syntax:
 *      "flag" => ["notEmpty", "boolean"]
 */
class Validator extends Component implements ValidatorInterface, LoggerAwareInterface
{
    use LoggerTrait, TranslatorTrait, SaturateTrait;

    /**
     * Return from validation rule to stop any future field validations. Internal contract.
     */
    const STOP_VALIDATION = -99;

    /**
     * @invisible
     * @var ValidatorConfig
     */
    private $config = null;

    /**
     * @var array|\ArrayAccess
     */
    private $data = [];

    /**
     * Validation rules, see class title for description.
     *
     * @var array
     */
    private $rules = [];

    /**
     * Error messages raised while validation.
     *
     * @var array
     */
    private $errors = [];

    /**
     * Errors provided from outside.
     *
     * @var array
     */
    private $registeredErrors = [];

    /**
     * If rule has no definer error message this text will be used instead. Localizable.
     *
     * @invisible
     * @var string
     */
    protected $defaultMessage = "[[Condition '{condition}' does not meet.]]";

    /**
     * @invisible
     * @var ContainerInterface
     */
    protected $container = null;

    /**
     * Validation context. Not validated.
     *
     * @var mixed
     */
    protected $context;

    /**
     * {@inheritdoc}
     *
     * @param array              $rules     Validation rules.
     * @param array|\ArrayAccess $data      Data or model to be validated.
     * @param ValidatorConfig    $config    Saturated using shared container
     * @param ContainerInterface $container Saturated using shared container
     *
     * @throws ScopeException
     */
    public function __construct(
        array $rules = [],
        $data = [],
        ValidatorConfig $config = null,
        ContainerInterface $container = null
    ) {
        $this->data = $data;
        $this->rules = $rules;

        $this->config = $this->saturate($config, ValidatorConfig::class);
        $this->container = $this->saturate($container, ContainerInterface::class);
    }

    /**
     * {@inheritdoc}
     */
    public function setRules(array $rules): ValidatorInterface
    {
        if ($this->rules == $rules) {
            return $this;
        }

        $this->rules = $rules;
        $this->reset();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setData($data): ValidatorInterface
    {
        $data = $this->extractData($data);
        if ($this->data === $data) {
            return $this;
        }

        $this->data = $data;
        $this->reset();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * {@inheritdoc}
     */
    public function isValid(): bool
    {
        $this->validate();

        return empty($this->errors) && empty($this->registeredErrors);
    }

    /**
     * {@inheritdoc}
     */
    public function registerError(string $field, string $error): ValidatorInterface
    {
        $this->registeredErrors[$field] = $error;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function flushRegistered(): ValidatorInterface
    {
        $this->registeredErrors = [];

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function hasErrors(): bool
    {
        return !$this->isValid();
    }

    /**
     * {@inheritdoc}
     */
    public function getErrors(): array
    {
        $this->validate();

        return $this->registeredErrors + $this->errors;
    }

    /**
     * {@inheritdoc}
     */
    public function setContext($context)
    {
        $this->context = $context;
    }

    /**
     * {@inheritdoc}
     */
    public function getContext()
    {
        return $this->context;
    }

    /**
     * Receive field from context data or return default value.
     *
     * @param string $field
     * @param mixed  $default
     *
     * @return mixed
     */
    public function getValue(string $field, $default = null)
    {
        $value = isset($this->data[$field]) ? $this->data[$field] : $default;

        return ($value instanceof EntityInterface || $value instanceof AccessorInterface)
            ? $value->packValue()
            : $value;
    }

    /**
     * Reset validation state.
     */
    public function reset()
    {
        $this->errors = [];
        $this->registeredErrors = [];
    }

    /**
     * Validate context data with set of validation rules.
     */
    protected function validate()
    {
        $this->errors = [];
        foreach ($this->rules as $field => $rules) {

            foreach ($rules as $rule) {
                if (isset($this->errors[$field])) {
                    //We are validating field till first error
                    continue;
                }

                //Condition is either rule itself or first array element
                $condition = is_string($rule) ? $rule : $rule[0];
                $arguments = is_string($rule) ? [] : $this->fetchArguments($rule);

                if (empty($this->getValue($field)) && !$this->config->emptyCondition($condition)) {
                    //There is no need to validate empty field except for special conditions
                    break;
                }

                if ($this->skipUnderEmptyCondition($rule)) {
                    continue;
                }

                $result = $this->check($field, $this->getValue($field), $condition, $arguments);

                if ($result === true) {
                    //No errors
                    continue;
                }

                if ($result === self::STOP_VALIDATION) {
                    //Validation has to be stopped per rule request
                    break;
                }

                if ($result instanceof CheckerInterface) {
                    //Failed inside checker, this is implementation agreement
                    if ($message = $result->getMessage($condition[1])) {

                        //Checker provides it's own message for condition
                        $this->addMessage(
                            $field,
                            is_string($rule) ? $message : $this->fetchMessage($rule, $message),
                            $condition,
                            $arguments
                        );

                        continue;
                    }
                }

                //Default message
                $message = $this->say($this->defaultMessage);

                //Recording error message
                $this->addMessage(
                    $field,
                    is_string($rule) ? $message : $this->fetchMessage($rule, $message),
                    $condition,
                    $arguments
                );
            }
        }
    }

    /**
     * Check field with given condition. Can return instance of Checker (data is not valid) to
     * clarify error.
     *
     * @param string $field
     * @param mixed  $value
     * @param mixed  $condition Reference, can be altered if alias exists.
     * @param array  $arguments Rule arguments if any.
     *
     * @return bool|CheckerInterface
     * @throws ValidationException
     */
    protected function check(string $field, $value, &$condition, array $arguments = [])
    {
        //Supports both class::func and class:func
        $condition = str_replace('::', ':', $this->config->resolveAlias($condition));

        try {
            if (!is_array($condition) && strpos($condition, ':')) {
                $condition = explode(':', $condition);
                if ($this->hasChecker($condition[0])) {
                    $checker = $this->getChecker($condition[0]);
                    $result = $checker->check($condition[1], $value, $arguments);

                    if ($result === false) {
                        //To let validation() method know that message should be handled via Checker
                        return $checker;
                    }

                    return $result;
                }
            }

            if (is_array($condition)) {
                //We are going to resolve class using constructor
                $condition[0] = is_object($condition[0])
                    ? $condition[0]
                    : $this->container->get($condition[0]);
            }

            //Value always coming first
            array_unshift($arguments, $value);

            return call_user_func_array($condition, $arguments);
        } catch (\Error $e) {
            throw new ValidationException("Invalid rule definition", $e->getCode(), $e);
        } catch (\Throwable $e) {
            $this->logException($field, func_get_arg(2), $e);

            return false;
        }
    }

    /**
     * Does validation config has alias defined for a given checker name or class exists
     *
     * @param string $name
     *
     * @return bool
     */
    protected function hasChecker(string $name): bool
    {
        if ($this->config->hasChecker($name)) {
            return true;
        }

        if (class_exists($name)) {
            $checker = $this->container->get($name);

            return $checker instanceof CheckerInterface;
        }

        return false;
    }

    /**
     * Get or create instance of validation checker.
     *
     * @param string $name
     *
     * @return CheckerInterface
     * @throws ValidationException
     */
    protected function getChecker(string $name): CheckerInterface
    {
        if (!$this->hasChecker($name)) {
            throw new ValidationException(
                "Unable to create validation checker defined by '{$name}' name"
            );
        }

        /** @var string $name */
        $name = $this->config->hasChecker($name) ? $this->config->checkerClass($name) : $name;

        return $this->container->get($name)->withValidator($this);
    }

    /**
     * Fetch validation rule arguments from rule definition.
     *
     * @param array $rule
     *
     * @return array
     */
    private function fetchArguments(array $rule): array
    {
        unset($rule[0], $rule['message'], $rule['error']);

        return array_values($rule);
    }

    /**
     * Fetch error message from rule definition or use default message. Method will check "message"
     * and "error" properties of definition.
     *
     * @param array  $rule
     * @param string $message Default message to use.
     *
     * @return string
     */
    private function fetchMessage(array $rule, string $message): string
    {
        if (isset($rule['message'])) {
            return $rule['message'];
        }

        if (isset($rule['error'])) {
            return $rule['error'];
        }

        return $message;
    }

    /**
     * Register error message for specified field. Rule definition will be interpolated into
     * message.
     *
     * @param string $field
     * @param string $message
     * @param mixed  $condition
     * @param array  $arguments
     */
    private function addMessage(string $field, string $message, $condition, array $arguments = [])
    {
        if (is_array($condition)) {
            if (is_object($condition[0])) {
                $condition[0] = get_class($condition[0]);
            }

            $condition = join('::', $condition);
        }

        $this->errors[$field] = \Spiral\interpolate(
            $message,
            compact('field', 'condition') + $arguments
        );
    }

    /**
     * @param string     $field
     * @param array      $condition
     * @param \Throwable $e
     */
    protected function logException(string $field, $condition, \Throwable $e)
    {
        if (is_array($condition)) {
            if (is_object($condition[0])) {
                $condition[0] = get_class($condition[0]);
            }

            $condition = join('::', $condition);
        }

        $this->logger()->error(
            "Condition '{condition}' failed with '{e}' while checking '{field}' field.",
            compact('condition', 'field') + ['e' => $e->getMessage()]
        );
    }

    /**
     * @param array|\ArrayAccess|EntityInterface $data
     *
     * @return array
     */
    private function extractData($data): array
    {
        if ($data instanceof EntityInterface) {
            return $data->getFields();
        }

        //Under consideration, might not be required
        if ($data instanceof \ArrayAccess && $data instanceof \Traversable) {
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $value;
            }

            return $result;
        }

        return $data;
    }

    /**
     * Does rule have condition.
     *
     * @param $rule
     *
     * @return bool
     */
    protected function skipUnderEmptyCondition($rule)
    {
        if (is_array($rule) && !empty($rule['condition']) && $this->hasCondition($rule['condition'])) {
            $condition = $this->getCondition($rule['condition']);
            if (!$condition->isMet()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does checker condition class exist.
     *
     * @param string $name
     *
     * @return bool
     */
    protected function hasCondition(string $name): bool
    {
        if (class_exists($name)) {
            $condition = $this->container->get($name);

            return $condition instanceof CheckerConditionInterface;
        }

        return false;
    }

    /**
     * Get or create instance of validation checker condition.
     *
     * @param string $name
     *
     * @return CheckerConditionInterface
     * @throws ValidationException
     */
    protected function getCondition(string $name): CheckerConditionInterface
    {
        if (!$this->hasCondition($name)) {
            throw new ValidationException(
                "Unable to create validation checker condition defined by '{$name}' name"
            );
        }

        return $this->container->get($name)->withValidator($this);
    }
}