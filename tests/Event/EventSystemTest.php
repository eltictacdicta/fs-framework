<?php

/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2013-2020 Carlos Garcia Gomez <neorazorx@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace Tests\Event;

use FSFramework\Event\ControllerEvent;
use FSFramework\Event\FSEvent;
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\ModelEvent;
use PHPUnit\Framework\TestCase;

class EventFixture extends FSEvent
{
    public bool $legacyProcessed = false;

    public function processLegacyExtensions(): void
    {
        $this->legacyProcessed = true;
    }
}

class EventModelFixture
{
    public string $table_name = 'fixture_table';
    public string $name = 'initial';
}

class EventSystemTest extends TestCase
{
    protected function tearDown(): void
    {
        FSEventDispatcher::reset();
        parent::tearDown();
    }

    public function testBaseEventStoresLegacyExtensionsAndData(): void
    {
        $event = new EventFixture();
        $extension = (object) ['type' => 'button', 'text' => 'Save'];

        $event
            ->addLegacyExtension($extension)
            ->setData(['foo' => 'bar'])
            ->set('baz', 42);

        $this->assertSame([$extension], $event->getLegacyExtensions());
        $this->assertSame(['foo' => 'bar', 'baz' => 42], $event->getData());
        $this->assertSame('bar', $event->get('foo'));
        $this->assertNull($event->get('missing'));
    }

    public function testDispatchWithLegacyInvokesListenersAndLegacyProcessing(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();
        $event = new EventFixture();

        $dispatcher->addListener('fixture.event', function (EventFixture $event): void {
            $event->set('listener', true);
        });

        $result = $dispatcher->dispatchWithLegacy($event, 'fixture.event');

        $this->assertSame($event, $result);
        $this->assertTrue($event->legacyProcessed);
        $this->assertTrue($event->get('listener'));
    }

    public function testDispatchControllerEventExposesControllerStateAndResponse(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();
        $controller = new \stdClass();
        $response = new \Symfony\Component\HttpFoundation\Response('ok');

        $dispatcher->addListener('controller.before_action', function (ControllerEvent $event) use ($controller, $response): void {
            $event->setController($controller);
            $event->setResponse($response);
        });

        $event = $dispatcher->dispatchControllerEvent('before_action', 'DemoController', ['mode' => 'test']);

        $this->assertSame('controller.before_action', ControllerEvent::BEFORE_ACTION);
        $this->assertSame('controller.after_action', ControllerEvent::AFTER_ACTION);
        $this->assertSame('controller.render', ControllerEvent::RENDER);
        $this->assertSame('controller.config', ControllerEvent::CONFIG);
        $this->assertSame('DemoController', $event->getControllerName());
        $this->assertSame('before_action', $event->getAction());
        $this->assertSame('test', $event->get('mode'));
        $this->assertSame($controller, $event->getController());
        $this->assertSame($response, $event->getResponse());
        $this->assertTrue($event->hasResponse());
    }

    public function testControllerEventCollectsLegacyExtensionsDuringProcessing(): void
    {
        $event = new ControllerEvent('DemoController', 'render');
        $extension = (object) ['type' => 'button', 'text' => 'Legacy'];

        $event->addLegacyExtension($extension);
        $event->processLegacyExtensions();

        $this->assertSame([0 => $extension], $event->get('extensions'));
    }

    public function testDispatchModelEventExposesModelHelpersAndCancellation(): void
    {
        $dispatcher = FSEventDispatcher::getInstance();
        $model = new EventModelFixture();

        $dispatcher->addListener('model.before_save', function (ModelEvent $event): void {
            $event->setModelAttribute('name', 'updated');
            $event->cancel('blocked');
        });

        $event = $dispatcher->dispatchModelEvent('before_save', $model);

        $this->assertSame('model.before_save', ModelEvent::BEFORE_SAVE);
        $this->assertSame('model.after_save', ModelEvent::AFTER_SAVE);
        $this->assertSame('model.before_delete', ModelEvent::BEFORE_DELETE);
        $this->assertSame('model.after_delete', ModelEvent::AFTER_DELETE);
        $this->assertSame('model.before_test', ModelEvent::BEFORE_TEST);
        $this->assertSame('model.after_test', ModelEvent::AFTER_TEST);
        $this->assertSame($model, $event->getModel());
        $this->assertSame(EventModelFixture::class, $event->getModelClass());
        $this->assertSame('fixture_table', $event->getTableName());
        $this->assertSame('before_save', $event->getAction());
        $this->assertTrue($event->isModelType(EventModelFixture::class));
        $this->assertSame('updated', $event->getModelAttribute('name'));
        $this->assertTrue($event->isCancelled());
        $this->assertSame('blocked', $event->getCancelReason());
    }
}
