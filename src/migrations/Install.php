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

		try {
			$this->createTable(
				Revalidator::$urisTableName,
				[
					'id'        => $this->primaryKey(),
					'uri'       => $this->string(),
					'sectionId' => $this->integer(11),
				]
			);

			$this->addForeignKey(
				null,
				Revalidator::$urisTableName,
				['sectionId'],
				Table::SECTIONS,
				['id'],
				'CASCADE'
			);
		} catch (Exception $exception) {
			if (!str_contains($exception->getMessage(), 'SQLSTATE[42S01]'))
				throw $exception;
		}
	}

	public function safeDown (): bool
	{
		// Revalidator
		// ---------------------------------------------------------------------

		$this->dropTableIfExists(Revalidator::$tableName);
		$this->dropTableIfExists(Revalidator::$urisTableName);

		return true;
	}

}