<?php

declare(strict_types=1);

namespace OCA\Video_Converter\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Video_Converter\Listener\LoadAdditionalScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'video_converter';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        if (class_exists(LoadAdditionalScriptsEvent::class)) {
            $context->registerEventListener(
                LoadAdditionalScriptsEvent::class,
                LoadAdditionalScriptsListener::class
            );
        }
    }

    public function boot(IBootContext $context): void {
    }
}
