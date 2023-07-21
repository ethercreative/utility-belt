<?php

namespace ether\utilitybelt\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\errors\BusyResourceException;
use craft\errors\SiteNotFoundException;
use craft\errors\StaleResourceException;
use craft\events\ModelEvent;
use craft\events\SectionEvent;
use craft\events\TemplateEvent;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\services\Sections;
use craft\web\twig\TemplateLoaderException;
use craft\web\View;
use ether\utilitybelt\jobs\RevalidateJob;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\InvalidConfigException;
use yii\base\NotSupportedException;
use yii\db\Exception;

class Revalidator extends Component
{

	public static $tableName = '{{%b_revalidate_jobs}}';

	public function init (): void
	{
		parent::init();

		Event::on(
			Element::class,
			Element::EVENT_AFTER_SAVE,
			[$this, 'onAfterElementSave']
		);

		Event::on(
			View::class,
			View::EVENT_AFTER_RENDER_TEMPLATE,
			[$this, 'onAfterRenderTemplate']
		);

		Event::on(
			Sections::class,
			Sections::EVENT_BEFORE_SAVE_SECTION,
			[$this, 'onBeforeSectionSave']
		);
	}

	public function onAfterElementSave (ModelEvent $event): void
	{
		/** @var Element $element */
		$element = $event->sender;

		if (ElementHelper::isDraftOrRevision($element))
			return;

		$this->push($element);
	}

	public function onAfterRenderTemplate (TemplateEvent $event): void
	{
		$this->injectAdditionalUrisTable($event);
	}

	public function onBeforeSectionSave (SectionEvent $event): void
	{
		if (!empty($event->section) && !empty($event->section->uid))
			$this->saveAdditionalURIs($event->section->uid);
	}

	/**
	 * Inject the markup for additional URIs
	 *
	 * @param TemplateEvent $event
	 *
	 * @return void
	 * @throws SiteNotFoundException|TemplateLoaderException
	 */
	public function injectAdditionalUrisTable (TemplateEvent $event): void
	{
		if ($event->template !== 'settings/sections/_edit.twig')
			return;

		$sectionId = $event->variables['sectionId'];

		if (empty($sectionId)) {
			$markup = Cp::fieldHtml('<div class="warning">Save this section to access additional revalidate URIs</div>', [
				'label' => 'Additional Revalidate URIs',
				'instructions' => 'Any additional URIs that need to be revalidated when this entry changes (i.e. indexes)',
				'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
			]);
		} else {
			$sectionUid = Craft::$app->getSections()->getSectionById($sectionId)->uid;
			$markup = Cp::editableTableFieldHtml([
				'label' => 'Additional Revalidate URIs',
				'instructions' => 'Any additional URIs that need to be revalidated when this entry changes (i.e. indexes)',
				'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
				'name' => 'bAdditionalRevalidateUris',
				'cols' => [
					[
						'type' => 'url',
						'heading' => 'URI'
					],
				],
				'rows' => $this->getAdditionalURIs($sectionUid, true),
				'initJs' => true,
				'allowAdd' => true,
				'allowDelete' => true,
			]);
		}

		$event->output = preg_replace(
			'/<\/div>\s*?<\/div><!-- #content-container -->/m',
			$markup . '</div></div><!-- #content-container -->',
			$event->output
		);
	}

	/**
	 * Get the additional URIs for the given section
	 *
	 * @param string $sectionUid
	 * @param bool $asRows
	 *
	 * @return array
	 */
	public function getAdditionalURIs (string $sectionUid, bool $asRows = false): array
	{
		$uris = Craft::$app->getProjectConfig()->get("utility-belt.revalidator.uris.$sectionUid") ?? [];

		if (!$asRows)
			return $uris;

		$rows = [];

		foreach ($uris as $uri)
			$rows[] = ['0' => $uri];

		return $rows;
	}

	/**
	 * Save additional URIs
	 *
	 * @param string $sectionUid
	 *
	 * @return void
	 * @throws BusyResourceException
	 * @throws StaleResourceException
	 * @throws ErrorException
	 * @throws \yii\base\Exception
	 * @throws InvalidConfigException
	 * @throws NotSupportedException
	 */
	public function saveAdditionalURIs (string $sectionUid): void
	{
		$request = Craft::$app->getRequest();

		if ($request->getIsConsoleRequest())
			return;

		// FIXME: Can add but can't delete
		$uris = $request->getBodyParam('bAdditionalRevalidateUris');
		$key = "utility-belt.revalidator.uris.$sectionUid";
		$config = Craft::$app->getProjectConfig();

		if (empty($uris))
		{
			$config->remove($key);
			return;
		}

		foreach ($uris as $i => $uri)
			$uris[$i] = $uri[0];

		$config->set($key, $uris);
	}

	/**
	 * Queue URI for revalidation
	 *
	 * @param Element $element
	 *
	 * @return void
	 * @throws Exception|\yii\base\Exception
	 */
	public function push (Element $element): void
	{
		/** @var RevalidateJob $job */
		[$id, $job] = $this->getJob();

		$uri = $element->uri;

		if (!empty($uri)) $job->uris[] = $uri;

		if ($element instanceof Entry)
		{
			$sectionUid = Craft::$app->getSections()->getSectionById($element->sectionId)->uid;
			$job->sectionUid = $sectionUid;

			foreach ($this->getAdditionalURIs($sectionUid) as $uri)
				$job->uris[] = $uri;
		}

		if (empty($id))
		{
			$id = Craft::$app->getQueue()->push($job);
			$this->storeJobId($id);
		} else $this->updateJob($id, $job);
	}

	/**
	 * Get or create the revalidate job
	 *
	 * @return array|null
	 * @throws Exception|\yii\base\Exception
	 */
	private function getJob (): ?array
	{
		$jobs = (new Query())
			->select('q.id, q.job')
			->from(['q' => Table::QUEUE])
			->innerJoin(['j' => self::$tableName], ['j.jobId' => 'q.id'])
			->where(['q.attempt' => null])
			->limit(1)
			->pairs();

		if (empty($jobs))
			return [null, new RevalidateJob()];

		[$id, $job] = $jobs[0];

		return [
			$id,
			Craft::$app->getQueue()->serializer->unserialize($job),
		];
	}

	/**
	 * Store the revalidate jobs ID
	 *
	 * @param int $id
	 *
	 * @return void
	 * @throws Exception
	 */
	private function storeJobId (int $id): void
	{
		Craft::$app->getDb()->createCommand()
		           ->insert(self::$tableName, ['jobId' => $id], false)
		           ->execute();
	}

	/**
	 * Update the given revalidate job
	 *
	 * @param int           $id
	 * @param RevalidateJob $job
	 *
	 * @return void
	 * @throws Exception
	 */
	private function updateJob (int $id, RevalidateJob $job): void
	{
		$job = Craft::$app->getQueue()->serializer->serialize($job);

		Craft::$app->getDb()->createCommand()
		           ->update(Table::QUEUE, ['job' => $job], ['id' => $id], null, false)
		           ->execute();
	}

}
