<?php

namespace Okay\Modules\Sviat\PersonalData\Policy;

use Okay\Core\EntityFactory;
use Okay\Entities\PagesEntity;
use Okay\Modules\Sviat\PersonalData\Forms\FormSettings;

/** Сторінка політики, на яку вела б мітка згоди за поточних налаштувань; для відмітки без доказу показу. */
final readonly class PolicyPage
{
    public function __construct(
        private FormSettings $formSettings,
        private EntityFactory $entityFactory,
    ) {}

    /** Те саме правило, що й на показі (PolicyLink): видима сторінка з придатною адресою, інакше null. */
    public function linkedId(): ?int
    {
        $id = $this->formSettings->policyPageId();
        if ($id === null) {
            return null;
        }
        $page = $this->entityFactory->get(PagesEntity::class)->findOne(['id' => $id, 'visible' => 1]);

        return is_object($page) && PolicyLink::href($id, [$page]) !== null ? $id : null;
    }
}
