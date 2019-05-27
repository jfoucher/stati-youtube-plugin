<?php
/**
 * Paginator.php
 *
 * Created By: jonathan
 * Date: 27/09/2017
 * Time: 20:08
 */

namespace Stati\Plugin\Youtube;

use Stati\Event\ConsoleOutputEvent;
use Stati\Event\SiteEvent;
use Stati\Event\SettingTemplateVarsEvent;
use Stati\Plugin\Plugin;

use Stati\Site\SiteEvents;
use YoutubeDl\YoutubeDl;


class Youtube extends Plugin
{
    protected $name = 'youtube';

    public static function getSubscribedEvents()
    {
        return array(
            SiteEvents::DID_READ_DATA => 'onSiteDateRead',
        );
    }

    public function onSiteDateRead(SiteEvent $event)
    {
        $site = $event->getSite();
        $data = $site->getData();
        $videos = [];
        $conf = $site->getConfig();
        if (!isset($conf['youtube_api_key']) || !isset($conf['youtube_channel'])) {
            return false;
        }
        $url = 'https://www.googleapis.com/youtube/v3/search?channelId=' . $conf['youtube_channel'] . '&part=snippet,id&order=date&maxResults=30&key=' . $conf['youtube_api_key'];

        $res = json_decode(file_get_contents($url));

        foreach($res->items as $item) {
            if ($item->id->kind === 'youtube#video') {
                $video = [
                    'id' => $item->id->videoId,
                    'title' => $item->snippet->title,
                    'description' => $item->snippet->description,
                    'publishedAt' => new \DateTime($item->snippet->publishedAt),
                    'url' => 'https://youtube.com/watch?v=' . $item->id->videoID,
                    'thumbnail' => $item->thumbnails->high->url,
                ];
                if (isset($conf['youtube_save_path'])) {
                    $dl = new YoutubeDl([
                        'continue' => true,
                        'format' => 'bestvideo',
                    ]);
                    $dl->setDownloadPath($conf['youtube_save_path']);

                    try {
                        $vid = $dl->download($video['url']);
                        $video['file'] = $vid->getFile()->getPath();
                        $site->getDispatcher()->dispatch(SiteEvents::CONSOLE_OUTPUT, new ConsoleOutputEvent('section', ['Downloaded video ' . $video['url'] . ' to ' . $vid->getFile()->getPath()]));
                    } catch (\Exception $e) {
                        // Failed to download
                        $site->getDispatcher()->dispatch(SiteEvents::CONSOLE_OUTPUT, new ConsoleOutputEvent('section', ['Failed to download video ' . $video['url'] . ' - ' . $e->getMessage()]));
                    }
                }

                $videos[] = $video;
            }
        }

        $data['videos'] = $videos;
        $site->setData($data);
        return $videos;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }
}
