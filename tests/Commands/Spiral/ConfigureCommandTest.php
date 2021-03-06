<?php
/**
 * spiral
 *
 * @author    Wolfy-J
 */

namespace Spiral\Tests\Commands;

use Spiral\Core\DotenvEnvironment;
use Spiral\Core\NullMemory;
use Spiral\Tests\BaseTest;

class ConfigureCommandTest extends BaseTest
{
    /**
     * This is, in a fact, one of the most important tests in framework since it's basically
     * complies application.
     */
    public function testConfigure()
    {
        $this->assertSame(
            0,
            $this->app->console->run('configure')->getCode()
        );
    }

    public function testConfigureAndKey()
    {
        $environment = new DotenvEnvironment(
            directory('root') . '.env',
            new NullMemory()
        );

        //This is very complex and MUST not fail!
        $this->assertSame(
            0,
            $this->console->run('configure', ['-k' => true])->getCode()
        );

        clearstatcache();
        $newEnvironment = new DotenvEnvironment(
            directory('root') . '.env',
            new NullMemory()
        );

        $this->assertNotSame(
            $environment->get('SPIRAL_KEY'),
            $newEnvironment->get('SPIRAL_KEY')
        );
    }
}