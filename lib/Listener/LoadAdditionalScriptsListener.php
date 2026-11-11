<?php

declare(strict_types=1);

namespace OCA\Video_Converter\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Video_Converter\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @template-implements IEventListener<LoadAdditionalScriptsEvent>
 */
class LoadAdditionalScriptsListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof LoadAdditionalScriptsEvent)) {
            return;
        }

        Util::addScript(Application::APP_ID, 'conversion');
        Util::addStyle(Application::APP_ID, 'style');
    }
}
