<?php

namespace Akeneo\Bundle\BatchBundle\Tests\Unit\Event;

use Akeneo\Bundle\BatchBundle\Event\InvalidItemEvent;

/**
 * Test related class
 *
 * @author    Gildas Quemener <gildas@akeneo.com>
 * @copyright 2013 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 */
class InvalidItemEventTest extends \PHPUnit\Framework\TestCase
{
    public function testAccessors()
    {
        $event = new InvalidItemEvent(
            'Foo\\Bar\\Baz',
            'No special reason.',
            array('%param%' => 'Item1'),
            array('foo' => 'baz')
        );

        $this->assertEquals('Foo\\Bar\\Baz', $event->getClass());
        $this->assertEquals('No special reason.', $event->getReason());
        $this->assertEquals(array('%param%' => 'Item1'), $event->getReasonParameters());
        $this->assertEquals(array('foo' => 'baz'), $event->getItem());
    }
}
