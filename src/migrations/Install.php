<?php

namespace ether\utilitybelt\migrations;

use craft\db\Migration;
use craft\db\Table;
use ether\utilitybelt\services\Revalidator;
use yii\db\Exception;

class Install extends Migration
{

	public function safeUp ()
	{
		// Revalidator
		// ---------------------------------------------------------------------

		try {
			$this->createTable(
				Revalidator::$tableName,
				['jobId' => $this->integer(11)]
			);

			$this->addForeignKey(
				null,
				Revalidator::$tableName,
				['jobId'],
				Table::QUEUE,
				['id'],
				'CASCADE'
			);
		} catch (Exception $exception) {
			if (!str_contains($exception->getMessage(), 'SQLSTATE[42S01]'))
				throw $exception;
		}

		// Link
		// ---------------------------------------------------------------------

		(new m220406_093529_create_link_element_table())->safeUp();

	}

	public function safeDown (): bool
	{
		$this->dropTableIfExists(Revalidator::$tableName);
		(new m220406_093529_create_link_element_table())->safeDown();

		return true;
	}

}