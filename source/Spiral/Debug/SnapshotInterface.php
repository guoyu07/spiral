<?php
/**
 * Spiral Framework.
 *
 * @license   MIT
 * @author    Anton Titov (Wolfy-J)
 */
namespace Spiral\Debug;

/**
 * Exception wrapper used to describe and show exception information in user friendly way.
 */
interface SnapshotInterface
{
    /**
     * Associated exception.
     *
     * @return \Throwable|\Exception
     */
    public function getException(): \Throwable;

    /**
     * Must return formatted exception message including exception class, location and etc.
     *
     * @return string
     */
    public function getMessage(): string;

    /**
     * Get shortened exception description in array form.
     *
     * @return array
     */
    public function describe(): array;

    /**
     * Render snapshot information into string or html.
     *
     * @return string
     */
    public function render(): string;

    /**
     * Report or store snapshot in known location. Used to store exception information for future
     * analysis.
     */
    public function report();
}