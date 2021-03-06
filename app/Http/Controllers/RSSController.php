<?php

/** @noinspection PhpUndefinedClassInspection */

namespace App\Http\Controllers;

use App\Configs;
use App\ModelFunctions\AlbumFunctions;
use App\ModelFunctions\SymLinkFunctions;
use App\Photo;
use File;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Feed\FeedItem;

class RSSController extends Controller
{
	/**
	 * @var AlbumFunctions
	 */
	private $albumFunctions;

	/**
	 * @var SymLinkFunctions
	 */
	private $symLinkFunctions;

	/**
	 * @param AlbumFunctions   $albumFunctions
	 * @param SymLinkFunctions $symLinkFunctions
	 */
	public function __construct(AlbumFunctions $albumFunctions, SymLinkFunctions $symLinkFunctions)
	{
		$this->albumFunctions = $albumFunctions;
		$this->symLinkFunctions = $symLinkFunctions;
	}

	public function make_enclosure($photo)
	{
		$enclosure = new \stdClass();

		$path = public_path($photo['url']);
		$enclosure->length = File::size($path);
		$enclosure->mime_type = File::mimeType($path);
		$enclosure->url = url('/' . $photo['url']);

		return $enclosure;
	}

	/**
	 * @return Collection
	 */
	public function getRSS()
	{
		if (Configs::get_value('rss_enable', '0') != '1') {
			abort(404);
		}

		$photos = Photo::with('album', 'owner')
			->where('created_at', '>=', Carbon::now()->subDays(intval(Configs::get_value('rss_recent_days', '7')))
				->toDateTimeString())
			->where(function ($q) {
				$q->whereIn(
					'album_id',
					$this->albumFunctions->getPublicAlbums()
				)
					->orWhere('public', '=', '1');
			})
			->limit(Configs::get_Value('rss_max_items', '100'))
			->get();

		$photos = $photos->map(function (Photo $photo_model) {
			$photo = $photo_model->prepareData();
			$this->symLinkFunctions->getUrl($photo_model, $photo);
			$id = null;
			if ($photo_model->album_id != null) {
				$album = $photo_model->album;
				if (!$album->full_photo_visible()) {
					$photo_model->downgrade($photo);
				}
				$id = '#' . $photo_model->album_id . '/' . $photo_model->id;
			} else { // Unsorted
				if (Configs::get_value('full_photo', '1') != '1') {
					$photo_model->downgrade($photo);
				}
				$id = 'view?p=' . $photo_model->id;
			}

			$photo['url'] = $photo['url'] ?: $photo['medium2x'] ?: $photo['medium'];
			// TODO: this will need to be fixed for s3 and when the upload folder is NOT the Lychee folder.
			$enclosure = $this->make_enclosure($photo);

			return FeedItem::create([
				'id' => url('/' . $id),
				'title' => $photo_model->title,
				'summary' => $photo_model->description,
				'updated' => $photo_model->created_at,
				'link' => $photo['url'],
				'enclosure' => $enclosure,
				'author' => $photo_model->owner->username,
			]);
		});

		return $photos;
	}
}
