<?php
/**
 * Paginator.php
 *
 * Created By: jonathan
 * Date: 27/09/2017
 * Time: 20:08
 */

namespace Stati\Plugin\Youtube;

use Stati\Entity\StaticFile;
use Stati\Event\ConsoleOutputEvent;
use Stati\Event\SiteEvent;
use Stati\Event\SettingTemplateVarsEvent;
use Stati\Plugin\Plugin;

use Stati\Site\SiteEvents;
use YoutubeDl\YoutubeDl;


class Youtube extends Plugin
{
    protected $name = 'youtube';

    protected $videos = [];

    public static function getSubscribedEvents()
    {
        return array(
            SiteEvents::WILL_READ_STATIC_FILES => 'onWillReadStaticFiles',
            SiteEvents::DID_READ_DATA => 'onSiteDateRead',
        );
    }

    public function onSiteDateRead(SiteEvent $event)
    {
        $site = $event->getSite();
        $data = $site->getData();
        $conf = $site->getConfig();

        if (isset($conf['youtube'])) {
            $data['videos'] = $this->videos;

            $site->setData($data);
        }
    }

    public function onWillReadStaticFiles(SiteEvent $event)
    {
        $site = $event->getSite();

        $conf = $site->getConfig();

        if (!isset($conf['youtube'])) {
            return false;
        }

        $cache_dir = isset($conf['youtube']['save_dir']) ? $conf['youtube']['save_dir'] : false;

        $num = isset($conf['youtube']['number']) ? $conf['youtube']['number'] : 10;

        if($cache_dir !== false && !is_dir($cache_dir)) {
            mkdir($cache_dir, 0777, true);
        }

        $url = 'https://www.googleapis.com/youtube/v3/search?channelId=' . $conf['youtube']['channel'] . '&part=snippet,id&order=date&maxResults=' . $num . '&key=' . $conf['youtube']['api_key'];

        $res = json_decode(file_get_contents($url));

        $downloaded = [];

        if ($cache_dir !== false) {
            if (is_file($cache_dir . '/videos.json')) {
                $downloaded = json_decode(file_get_contents($cache_dir . '/videos.json'));
            }

            $site->getDispatcher()->dispatch(SiteEvents::CONSOLE_OUTPUT, new ConsoleOutputEvent('section', ['Will download videos']));
        }

        $site->getDispatcher()->dispatch(SiteEvents::CONSOLE_OUTPUT, new ConsoleOutputEvent('section', ['Got '.count($res->items).' videos from Youtube']));


        foreach($res->items as $item) {
            if ($item->id->kind === 'youtube#video') {
                $thumbnail = null;
                if (isset($item->snippet->thumbnails)) {
                    $tc = file_get_contents($item->snippet->thumbnails->high->url);
                    $thumbnail = $cache_dir . '/' . $item->id->videoId.'.jpg';
                    file_put_contents($thumbnail, $tc);
                }
                $video = [
                    'id' => $item->id->videoId,
                    'title' => $item->snippet->title,
                    'description' => $item->snippet->description,
                    'publishedAt' => (new \DateTime($item->snippet->publishedAt))->format('Y-m-d H:i:s'),
                    'url' => 'https://youtube.com/watch?v=' . $item->id->videoId,
                    'thumbnail' => $thumbnail,
                ];

                if ($cache_dir !== false) {
                    if (in_array($video['id'], $downloaded)) {
                        $site->getDispatcher()->dispatch(SiteEvents::CONSOLE_OUTPUT, new ConsoleOutputEvent('section', ['Video ' . $video['id']. ' has already been downloaded']));

                        $video['file'] = $cache_dir . '/' . $video['id'].'.mp4';
                        $this->videos[] = $video;
                        continue;
                    }

                    $dl = new YoutubeDl();

                    $dl->setDownloadPath($cache_dir);

                    try {
                        $site->getDispatcher()->dispatch(SiteEvents::CONSOLE_OUTPUT, new ConsoleOutputEvent('section', ['Downloading video ' . $video['url']]));
                        $vid = $dl->download($video['url']);

                        rename($vid->getFile()->getPathname(), $vid->getFile()->getPath().'/' . $video['id'].'.mp4');

                        $video['file'] = $cache_dir . '/' . $video['id'].'.mp4';
                        $site->getDispatcher()->dispatch(SiteEvents::CONSOLE_OUTPUT, new ConsoleOutputEvent('section', ['Downloaded video ' . $video['url'] . ' to ' . $vid->getFile()->getPath()]));
                        $downloaded[] = $video['id'];
                    } catch (\Exception $e) {
                        // Failed to download
                        $site->getDispatcher()->dispatch(SiteEvents::CONSOLE_OUTPUT, new ConsoleOutputEvent('section', ['Failed to download video ' . $video['url'] . ' - ' . $e->getMessage()]));
                    }
                }
                $this->videos[] = $video;
            }
        }

        if ($cache_dir !== false) {
            file_put_contents($cache_dir . '/videos.json', json_encode($downloaded));
        }
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }
}
