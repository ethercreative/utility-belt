<?php

namespace ether\utilitybelt\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table;
use ether\utilitybelt\fields\LinkField;

/**
 * m220406_093529_create_link_element_table migration.
 */
class m220406_093529_create_link_element_table extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): void
    {
        $this->createTable(LinkField::TABLE, [
			'fieldId' => $this->integer(11),
	        'sourceId' => $this->integer(11),
	        'targetId' => $this->integer(11),
        ]);

		$this->addForeignKey(null, LinkField::TABLE, 'fieldId', Table::FIELDS, 'id', 'CASCADE');
		$this->addForeignKey(null, LinkField::TABLE, 'sourceId', Table::ELEMENTS, 'id', 'CASCADE');
		$this->addForeignKey(null, LinkField::TABLE, 'targetId', Table::ELEMENTS, 'id', 'CASCADE');

		$this->createIndex(null, LinkField::TABLE, ['fieldId', 'sourceId', 'targetId'], true);
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
		$this->dropTableIfExists(LinkField::TABLE);

		return true;
    }
}
