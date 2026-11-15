<?php

use Okay\Core\Design;
use Okay\Core\FrontTranslations;
use Okay\Core\Languages;
use Okay\Core\OkayContainer\Reference\ServiceReference as SR;
use Okay\Modules\Sviat\PersonalData\Consent\ErrorText;
use Okay\Modules\Sviat\PersonalData\Consent\RenderProof;
use Okay\Modules\Sviat\PersonalData\Consent\RequestState;
use Okay\Modules\Sviat\PersonalData\Core\SchemaState;
use Okay\Modules\Sviat\PersonalData\Forms\FormRegistry;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;
use Okay\Modules\Sviat\PersonalData\Plugins\ConfirmPersonalDataProcessingPlugin;

return [
    ConfirmPersonalDataProcessingPlugin::class => [
        'class' => ConfirmPersonalDataProcessingPlugin::class,
        'arguments' => [
            new SR(Design::class),
            new SR(SchemaState::class),
            new SR(FormRegistry::class),
            new SR(FormSettings::class),
            new SR(Languages::class),
            new SR(FrontTranslations::class),
            new SR(RenderProof::class),
            new SR(RequestState::class),
            new SR(ErrorText::class),
        ],
    ],
];
