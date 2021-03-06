<?php
/**
 * spiral
 *
 * @author    Wolfy-J
 */

namespace Spiral\Http\Request;

use Spiral\Http\Exceptions\InputException;
use Spiral\Validation\ValidatesEntity;
use Spiral\Validation\ValidatorInterface;

/**
 * Request filter is data entity which uses input manager to populate it's fields, model can
 * perform input filtering, value routing (query, data, files) and validation.
 *
 * Attention, you can not inherit one request from another at this moment. You can use generic
 * validation rules for your input fields.
 *
 * Please do not request instance without using container, constructor signature might change over
 * time (or another request filter class can be created with inheritance and composition support).
 *
 * Example schema definition:
 * const SCHEMA = [
 *       //identical to "data:name"
 *      'name'   => 'post:name',
 *
 *       //field name will used as search criteria in query ("query:field")
 *      'field'  => 'query',
 *
 *       //Yep, that's too
 *      'file'   => 'file:images.preview',
 *
 *       //Alias for InputManager->isSecure(),
 *      'secure' => 'isSecure'
 *
 *       //Iterate over file:uploads array with model UploadFilter and isolate it in uploads.*
 *      'uploads' => [UploadFilter::class, "uploads.*", "file:upload"],
 *
 *      //Nested model associated with address subset of data
 *      'address' => AddressRequest::class,
 *
 *       //Identical to previous definition
 *      'address' => [AddressRequest::class, "address"]
 * ];
 *
 * You can declare as source (query, file, post and etc) as source plus origin name (file:files.0).
 * Available sources: uri, path, method, isSecure, isAjax, isJsonExpected, remoteAddress.
 * Plus named sources (bags): header, data, post, query, cookie, file, server, attribute.
 */
class RequestFilter extends ValidatesEntity
{
    /**
     * Defines request data mapping (input => request property)
     */
    const SCHEMA = [];

    /**
     * @var InputMapper
     */
    private $mapper;

    /**
     * Cached set of errors from previous validation.
     *
     * @var array
     */
    private $lastErrors = [];

    /**
     * @param InputInterface     $input
     * @param ValidatorInterface $validator
     *
     * @throws InputException If schema is empty.
     */
    public function __construct(InputInterface $input = null, ValidatorInterface $validator = null)
    {
        //We are going to populate input fields manually
        parent::__construct([], $validator);

        $this->mapper = new InputMapper($this->getSchema());
        if (!empty($input)) {
            $this->initValues($input, $validator);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setField(string $name, $value, bool $filter = true)
    {
        $this->lastErrors = [];

        return parent::setField($name, $value, $filter);
    }

    /**
     * {@inheritdoc}
     */
    public function __unset($offset)
    {
        $this->lastErrors = [];
        parent::__unset($offset);
    }

    /**
     * Set request values based on a given input interface.
     *
     * @param InputInterface     $input
     * @param ValidatorInterface $validator Validator to propagate to sub entities. To be cloned.
     */
    public function initValues(InputInterface $input, ValidatorInterface $validator = null)
    {
        //Emptying the model
        $this->flushFields();

        foreach ($this->mapper->createValues($input, $validator) as $field => $value) {
            $this->setField($field, $value);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param bool $lastErrors Set to true to return previous set of errors without re-validation.
     *
     * @throws InputException When entity has non proper configuration.
     */
    public function getErrors(bool $lastErrors = false): array
    {
        if ($lastErrors && !empty($this->lastErrors)) {
            return $this->lastErrors;
        }

        //Making sure that each error point to proper input origin
        return $this->lastErrors = $this->mapper->originateErrors(parent::getErrors());
    }

    /**
     * @return array
     */
    public function __debugInfo()
    {
        return [
            'valid'  => $this->isValid(),
            'fields' => $this->getFields(),
            'errors' => $this->getErrors()
        ];
    }

    /**
     * @return array
     *
     * @throws InputException
     */
    protected function getSchema(): array
    {
        if (empty(static::SCHEMA)) {
            throw new InputException("Unable to initiate RequestFilter with empty schema");
        }

        return static::SCHEMA;
    }

    /**
     * Pass context to validator.
     *
     * @param mixed $context
     */
    public function setContext($context)
    {
        $this->getValidator()->setContext($context);
    }

    /**
     * Get context from validator.
     *
     * @return mixed
     */
    public function getContext()
    {
        return $this->getValidator()->getContext();
    }
}