<?php

namespace ether\utilitybelt\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;

class RevalidateJob extends BaseJob
{

	public $sectionUid = null;
	public $uris = [];
	public $volume = null;
	public $folder = null;
	public $filename = null;

	protected function defaultDescription (): string
	{
		return 'Revalidating Front-end';
	}

	public function execute ($queue): void
	{
		// Skip if localhost
		$frontendUrl = getenv('FRONTEND_URL');
		$revalidateToken = getenv('REVALIDATE_TOKEN');

		if (empty($frontendUrl) || empty($revalidateToken) || str_contains($frontendUrl, 'local'))
			return;

		$this->uris = array_unique($this->uris);

		$client = Craft::createGuzzleClient();
		$url = $frontendUrl . '/api/revalidate?token=' . getenv('REVALIDATE_TOKEN') . '&path=/';
		$total = count($this->uris);
		$i = 0;
		$section = null;

		if (!empty($this->sectionUid))
		{
			$section = Craft::$app->getSections()->getSectionByUid($this->sectionUid);
			$entriesInSection = Entry::find()->sectionId($section->id)->count();

			$urisWithTemplates = 0;
			foreach ($this->uris as $uri)
				if (str_contains($uri, '{'))
					$urisWithTemplates++;

			$total = ($total - $urisWithTemplates) + ($entriesInSection * $urisWithTemplates);
		}

		foreach ($this->uris as $uri)
		{
			if (empty($this->sectionUid) || !str_contains($uri, '{')) {
				$client->get($url . ltrim($uri, '/'));
				$queue->setProgress(++$i / $total * 100, $uri);
			} else {
				foreach (Entry::find()->sectionId($section->id)->all() as $entry)
				{
					$parsedUrl = Craft::$app->getView()->renderObjectTemplate(
						$uri,
						$entry
					);

					$client->get($url . ltrim($parsedUrl, '/'));
					$queue->setProgress(++$i / $total * 100, $uri);
				}
			}
		}

		if ($this->volume && $this->folder && $this->filename && $doKey = getenv('DO_API_KEY')) {
			$headers = [
				'Content-Type' => 'application/json',
				'Authorization' => "Bearer $doKey",
			];

			$response = $client
				->get('https://api.digitalocean.com/v2/cdn/endpoints?per_page=200', ['headers' => $headers])
				->getBody()
				->getContents();

			$spaces = json_decode($response)->endpoints;

			$spaces = array_values(array_filter($spaces, function ($space) {
				return $space->endpoint === $this->volume;
			}));

			if (!empty($spaces)) {
				$id = $spaces[0]->id;

				$client->request('DELETE', "https://api.digitalocean.com/v2/cdn/endpoints/$id/cache", [
					'headers' => $headers,
					'json' => ['files' => ["$this->folder/$this->filename", "$this->folder/*/$this->filename"]]
				]);
			}

			$queue->setProgress(100);
		}
	}

}
