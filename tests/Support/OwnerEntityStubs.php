<?php

/**
 * Сутності модулів-власників форм для прогону без цих модулів (форк, CI). Справжній клас, якщо модуль є,
 * перемагає: class_exists спершу пробує автозавантаження. Таблиці збігаються з модулями-власниками.
 */

namespace Okay\Modules\Sviat\InformBackInStock\Entities {
    if (!class_exists(InformBackInStockEntity::class)) {
        class InformBackInStockEntity extends \Okay\Core\Entity\Entity
        {
            protected static $table = '__inform_stock';
            protected static $tableAlias = 'i_st';
        }
    }
}

namespace Okay\Modules\Sviat\PartsSelection\Entities {
    if (!class_exists(PartsSelectionEntity::class)) {
        class PartsSelectionEntity extends \Okay\Core\Entity\Entity
        {
            protected static $table = 'sviat__parts_selection';
            protected static $tableAlias = 'p_st';
        }
    }
}

namespace Okay\Modules\Sviat\OrderReturns\Entities {
    if (!class_exists(OrderReturnEntity::class)) {
        class OrderReturnEntity extends \Okay\Core\Entity\Entity
        {
            protected static $table = 'sviat__order_returns';
            protected static $tableAlias = 'o_ret';
        }
    }
}
