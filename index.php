<?php

declare(strict_types=1);

use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Remote;
use Kirby\Http\Url;
use Kirby\Toolkit\Html;
use Kirby\Toolkit\Str;
use Kirby\Toolkit\V;

if (function_exists('hnzioShareEmbedPluginOptions') === false) {
    function hnzioShareEmbedPluginOptions(): array
    {
        $defaultsFile = __DIR__ . '/config.sample.php';
        $localFile = __DIR__ . '/config.php';

        $defaults = is_file($defaultsFile) ? require $defaultsFile : [];
        $local = is_file($localFile) ? require $localFile : [];

        if (is_array($defaults) === false) {
            $defaults = [];
        }

        if (is_array($local) === false) {
            $local = [];
        }

        return array_replace_recursive($defaults, $local);
    }
}

if (function_exists('hnzShareEmbedPluginOptions') === false) {
    function hnzShareEmbedPluginOptions(): array
    {
        return hnzioShareEmbedPluginOptions();
    }
}

if (function_exists('hnzioShareEmbedOption') === false) {
    function hnzioShareEmbedOption(string $key, mixed $default = null): mixed
    {
        return option('hnzio.share-embed.' . $key, option('hnz.share-embed.' . $key, $default));
    }
}

final class HnzioShareEmbedService
{
    private const SCHEMA_VERSION = 32;

    public function __construct(
        private Kirby\Cms\App $kirby
    ) {
    }

    public function resolve(string $sourceUrl, array $options = []): array
    {
        $sourceUrl = trim($sourceUrl);
        $force = $this->isTruthy($options['force'] ?? null);
        $freeze = $this->pluginOption('freeze', true) !== false;
        $authorNameHtml = null;
        $cached = null;

        $this->ensureStorage();
        $cachePath = $this->cachePath($sourceUrl);

        if ($force === false && F::exists($cachePath)) {
            $cached = $this->readCache($cachePath);
            if ($cached !== null) {
                $schemaMatches = (($cached['schemaVersion'] ?? null) === self::SCHEMA_VERSION);
                $cacheComplete = $this->hasRequiredCacheFields($cached);
                if ($freeze && $schemaMatches) {
                    if ($cacheComplete || $this->shouldRetryIncompleteCache($cached) === false) {
                        return $cached;
                    }
                }

                if ($freeze === false && $this->isFresh($cached) && $cacheComplete) {
                    return $cached;
                }
            }
        }

        $platform = $this->detectPlatform($sourceUrl);
        $metadataUrl = $sourceUrl;
        if (($platform['id'] ?? '') === 'youtube' && !empty($platform['videoId'])) {
            // Normalize away transient YouTube query params (e.g. si/is/list/start), fetch from canonical watch URL.
            $metadataUrl = 'https://www.youtube.com/watch?v=' . rawurlencode((string)$platform['videoId']);
        }
        $meta = $this->fetchMetadata($metadataUrl, $platform);
        $pocketPodcastUrl = $this->isPocketCastsUrl($sourceUrl) ? $this->pocketCastsPodcastPageUrl($sourceUrl) : null;

        if ($platform['id'] === 'web' && $this->isPocketCastsUrl($sourceUrl)) {
            $podcastPageUrl = $pocketPodcastUrl;
            if ($podcastPageUrl !== null) {
                $podcastMeta = $this->fetchMetadata($podcastPageUrl, $platform);

                if (empty($meta['audioPodcastTitle']) && !empty($podcastMeta['audioPodcastTitle'])) {
                    $meta['audioPodcastTitle'] = (string)$podcastMeta['audioPodcastTitle'];
                }
                if (empty($meta['audioPodcastTitle']) && !empty($podcastMeta['title'])) {
                    $meta['audioPodcastTitle'] = (string)$podcastMeta['title'];
                }
                if (empty($meta['audioPodcastWebsiteUrl']) && !empty($podcastMeta['audioPodcastWebsiteUrl'])) {
                    $meta['audioPodcastWebsiteUrl'] = (string)$podcastMeta['audioPodcastWebsiteUrl'];
                }
                if (empty($meta['audioFeedUrl']) && !empty($podcastMeta['audioFeedUrl'])) {
                    $meta['audioFeedUrl'] = (string)$podcastMeta['audioFeedUrl'];
                }
                if (empty($meta['audioFeedFormat']) && !empty($podcastMeta['audioFeedFormat'])) {
                    $meta['audioFeedFormat'] = (string)$podcastMeta['audioFeedFormat'];
                }
                if (!empty($podcastMeta['image'])) {
                    $meta['favicon'] = (string)$podcastMeta['image'];
                }
            }
        }

        if ($platform['id'] === 'bluesky') {
            $actor = $this->blueskyActorFromUrl($sourceUrl);
            $profile = $actor ? $this->fetchBlueskyProfile($actor) : null;

            if (is_array($profile)) {
                $handle = strtolower((string)($profile['handle'] ?? ''));
                if ($handle !== '') {
                    $platform['authorLabel'] = '@' . $handle . '@bluesky';
                    $platform['authorUrl'] = 'https://bsky.app/profile/' . rawurlencode($handle);
                }

                if (!empty($profile['displayName'])) {
                    $meta['authorName'] = (string)$profile['displayName'];
                }

                if (!empty($profile['avatar'])) {
                    $meta['authorImage'] = (string)$profile['avatar'];
                }
            }
        }

        if ($platform['id'] === 'mastodon') {
            $mastodonHost = (string)($platform['mastodonHost'] ?? '');
            $mastodonUser = (string)($platform['mastodonUser'] ?? '');
            $profile = $this->fetchMastodonProfile($mastodonHost, $mastodonUser);

            if (is_array($profile)) {
                if (!empty($profile['displayName'])) {
                    $meta['authorName'] = (string)$profile['displayName'];
                }

                if (!empty($profile['displayNameRaw'])) {
                    $authorNameHtml = $this->renderDisplayNameWithCustomEmojis(
                        (string)$profile['displayNameRaw'],
                        is_array($profile['emojis'] ?? null) ? $profile['emojis'] : [],
                        sha1($sourceUrl)
                    );
                }

                if (!empty($profile['avatar'])) {
                    $meta['authorImage'] = (string)$profile['avatar'];
                }

                $acct = strtolower(trim((string)($profile['acct'] ?? '')));
                if ($acct !== '') {
                    $acct = ltrim($acct, '@');
                    if (!str_contains($acct, '@') && $mastodonHost !== '') {
                        $acct .= '@' . $mastodonHost;
                    }
                    $platform['authorLabel'] = '@' . $acct;
                }

                if (!empty($profile['url']) && V::url((string)$profile['url'])) {
                    $platform['authorUrl'] = (string)$profile['url'];
                } elseif (!empty($profile['username']) && $mastodonHost !== '') {
                    $platform['authorUrl'] = 'https://' . $mastodonHost . '/@' . rawurlencode((string)$profile['username']);
                }
            }
        }

        $data = [
            'schemaVersion'  => self::SCHEMA_VERSION,
            'sourceUrl'      => $sourceUrl,
            'canonicalUrl'   => $this->safeUrl((string)($meta['canonicalUrl'] ?? '')) ?? $sourceUrl,
            'platform'       => $platform['id'],
            'platformLabel'  => $options['network'] ?? $platform['label'],
            'title'          => $this->cleanText((string)($options['title'] ?? $meta['title'] ?? '')),
            'description'    => $this->cleanText((string)($options['desc'] ?? $meta['description'] ?? '')),
            'authorLabel'    => $this->cleanText((string)($options['author'] ?? $platform['authorLabel'] ?? $meta['authorName'] ?? $platform['fallbackAuthor'])),
            'authorName'     => $this->cleanText((string)($meta['authorName'] ?? '')),
            'authorNameHtml' => $authorNameHtml,
            'authorUrl'      => $this->safeUrl((string)($options['profile'] ?? $platform['authorUrl'] ?? '')),
            'publishedTime'  => $meta['publishedTime'] ?? null,
            'audioPodcastTitle' => $this->cleanText((string)($meta['audioPodcastTitle'] ?? '')),
            'audioEpisodeNumber' => $this->cleanText((string)($meta['audioEpisodeNumber'] ?? '')),
            'audioPodcastUrl' => $this->safeUrl((string)$pocketPodcastUrl),
            'audioPodcastWebsiteUrl' => $this->safeUrl((string)($meta['audioPodcastWebsiteUrl'] ?? '')),
            'audioFeedUrl' => $this->safeUrl((string)($meta['audioFeedUrl'] ?? '')),
            'audioFeedFormat' => strtolower($this->cleanText((string)($meta['audioFeedFormat'] ?? ''))),
            'audioDuration' => $this->cleanText((string)($meta['audioDuration'] ?? '')),
            'videoDuration' => $this->cleanText((string)($meta['videoDuration'] ?? $meta['audioDuration'] ?? '')),
            'imageRemoteUrl' => $this->safeUrl((string)($options['image'] ?? $meta['image'] ?? '')),
            'imageLocalUrl'  => null,
            'imageLicenseText' => $this->cleanText((string)($meta['imageLicenseText'] ?? '')),
            'imageLicenseUrl' => $this->safeUrl((string)($meta['imageLicenseUrl'] ?? '')),
            'imageSafeModeBlocked' => false,
            'faviconRemoteUrl' => $this->safeUrl((string)($options['favicon'] ?? $meta['favicon'] ?? '')),
            'faviconLocalUrl' => null,
            'authorImageRemoteUrl' => $this->safeUrl((string)($options['avatar'] ?? $meta['authorImage'] ?? '')),
            'authorImageLocalUrl' => null,
            'fetchedAt'      => date(DATE_ATOM),
            'status'         => 'ok',
        ];

        if (is_array($cached)) {
            $data = $this->mergeWithCachedFallbacks($data, $cached);
        }

        if ($data['platform'] === 'youtube') {
            $sourceVideoId = $this->youtubeId((string)$data['sourceUrl']);
            $canonicalVideoId = $this->youtubeId((string)$data['canonicalUrl']);
            if ($sourceVideoId !== null && $canonicalVideoId === null) {
                $data['canonicalUrl'] = (string)$data['sourceUrl'];
            }
        } elseif ($data['platform'] === 'vimeo') {
            $sourceVideoId = $this->vimeoId((string)$data['sourceUrl']);
            $canonicalVideoId = $this->vimeoId((string)$data['canonicalUrl']);
            if ($sourceVideoId !== null && $canonicalVideoId === null) {
                $data['canonicalUrl'] = (string)$data['sourceUrl'];
            }
        }

        if ($data['title'] === '') {
            $data['title'] = $platform['fallbackTitle'];
        }

        if ($data['authorLabel'] === '') {
            $data['authorLabel'] = $platform['fallbackAuthor'];
        }

        if ($data['platform'] === 'bluesky' && preg_match('/^@did:plc:[^@]+@bluesky$/i', (string)$data['authorLabel']) === 1) {
            $handleFromTitle = $this->extractBlueskyHandleFromText((string)$data['title']);
            if ($handleFromTitle !== null) {
                $data['authorLabel'] = '@' . $handleFromTitle . '@bluesky';
                $data['authorUrl'] = 'https://bsky.app/profile/' . rawurlencode($handleFromTitle);
                if (is_string($data['authorImageRemoteUrl']) && str_contains($data['authorImageRemoteUrl'], '/did:plc:')) {
                    $data['authorImageRemoteUrl'] = 'https://unavatar.io/bluesky/' . rawurlencode($handleFromTitle);
                }
            }
        }

        if ($data['platform'] === 'mastodon' && $data['authorImageRemoteUrl'] === null) {
            $fromStatus = $this->fetchMastodonAvatarFromStatus((string)$data['canonicalUrl']);
            if ($fromStatus !== null) {
                $data['authorImageRemoteUrl'] = $fromStatus;
            }
        }

        if ($data['platform'] === 'mastodon' && $data['authorImageRemoteUrl'] === null) {
            $authorLabel = ltrim((string)$data['authorLabel'], '@');
            if ($authorLabel !== '') {
                $data['authorImageRemoteUrl'] = $this->safeUrl('https://unavatar.io/' . rawurlencode($authorLabel));
            }
        }

        if ($data['authorUrl'] === null && !empty($platform['authorUrl'])) {
            $data['authorUrl'] = $platform['authorUrl'];
        }

        if (
            $data['platform'] === 'web' &&
            $data['imageRemoteUrl'] !== null &&
            !$this->isWebImageAllowed(
                $data['canonicalUrl'],
                (string)$data['imageRemoteUrl'],
                (string)($data['imageLicenseText'] ?? ''),
                $data['imageLicenseUrl'] ?? null
            )
        ) {
            $data['imageRemoteUrl'] = null;
            $data['imageLocalUrl'] = null;
            $data['imageSafeModeBlocked'] = true;
        }

        if ($data['imageRemoteUrl']) {
            $downloaded = $this->downloadImage($data['imageRemoteUrl'], sha1($sourceUrl));
            if ($downloaded !== null) {
                $data['imageLocalUrl'] = $downloaded;
            }
        }

        if ($data['platform'] === 'youtube' && $data['imageLocalUrl'] === null && !empty($platform['videoId'])) {
            $thumb = 'https://i.ytimg.com/vi/' . rawurlencode($platform['videoId']) . '/hqdefault.jpg';
            $downloaded = $this->downloadImage($thumb, sha1($sourceUrl));
            if ($downloaded !== null) {
                $data['imageRemoteUrl'] = $thumb;
                $data['imageLocalUrl'] = $downloaded;
            }
        }

        if ($data['faviconRemoteUrl'] === null) {
            $data['faviconRemoteUrl'] = $this->defaultFaviconUrl($data['canonicalUrl']);
        }

        if ($data['authorImageRemoteUrl'] === null && $data['authorUrl']) {
            $data['authorImageRemoteUrl'] = $this->fetchProfileImageCandidate($data['authorUrl']);
        }

        if ($data['authorImageRemoteUrl'] === null && $data['platform'] === 'bluesky') {
            $handle = $this->blueskyActorFromUrl((string)$data['authorUrl']);
            if ($handle) {
                $data['authorImageRemoteUrl'] = $this->fetchBlueskyAvatar($handle);
                if ($data['authorImageRemoteUrl'] === null) {
                    $data['authorImageRemoteUrl'] = $this->safeUrl('https://unavatar.io/bluesky/' . rawurlencode($handle));
                }
            }
        }

        if ($data['faviconRemoteUrl']) {
            $faviconLocal = $this->downloadImage($data['faviconRemoteUrl'], sha1($sourceUrl . ':favicon'));
            if ($faviconLocal) {
                $data['faviconLocalUrl'] = $faviconLocal;
            }
        }

        if ($data['authorImageRemoteUrl']) {
            $authorImageLocal = $this->downloadImage($data['authorImageRemoteUrl'], sha1($sourceUrl . ':author'));
            if ($authorImageLocal) {
                $data['authorImageLocalUrl'] = $authorImageLocal;
            }
        }

        $this->safeWrite(
            $cachePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $data;
    }

    public function render(array $data): string
    {
        $sourceUrl = $data['canonicalUrl'] ?? $data['sourceUrl'] ?? null;
        if (!is_string($sourceUrl) || V::url($sourceUrl) !== true) {
            return '';
        }

        $platform = (string)($data['platform'] ?? 'web');
        $title = $this->cleanText((string)($data['title'] ?? $sourceUrl));
        $description = $this->cleanText((string)($data['description'] ?? ''));
        $platformLabel = $this->cleanText((string)($data['platformLabel'] ?? 'Web'));
        $authorLabel = $this->cleanText((string)($data['authorLabel'] ?? ''));
        $authorName = $this->cleanText((string)($data['authorName'] ?? ''));
        $authorNameHtml = is_string($data['authorNameHtml'] ?? null) ? trim((string)$data['authorNameHtml']) : '';
        $authorUrl = $this->safeUrl((string)($data['authorUrl'] ?? ''));
        $originalSourceUrl = $this->safeUrl((string)($data['sourceUrl'] ?? ''));
        $isYoutube = ($platform === 'youtube');
        $isVideo = in_array($platform, ['youtube', 'vimeo'], true);
        $youtubeId = $isYoutube ? ($this->youtubeId($sourceUrl) ?? ($originalSourceUrl ? $this->youtubeId($originalSourceUrl) : null)) : null;
        $vimeoId = $platform === 'vimeo' ? ($this->vimeoId($sourceUrl) ?? ($originalSourceUrl ? $this->vimeoId($originalSourceUrl) : null)) : null;
        $hostLabel = $this->hostLabel($sourceUrl);
        $citeName = $this->cleanText($title !== '' ? $title : $hostLabel);
        if ($citeName === '') {
            $citeName = 'Beitrag';
        }
        $avatarLocal = $data['authorImageLocalUrl'] ?? $data['faviconLocalUrl'] ?? null;
        $faviconLocal = $data['faviconLocalUrl'] ?? null;
        $publishedSource = $data['publishedTime'] ?? null;
        $published = $this->formatPublishedDate($publishedSource, false);
        $publishedIso = $this->toIsoDateTime($publishedSource);
        $originButtonUrl = $this->safeUrl((string)($data['originButtonUrl'] ?? $sourceUrl));
        if ($originButtonUrl === '') {
            $originButtonUrl = $sourceUrl;
        }
        $originButtonLabel = trim((string)($data['originButtonLabel'] ?? ''));
        $originButtonIsInternal = $this->isTruthy($data['originButtonIsInternal'] ?? false);
        $originButtonContent = $originButtonLabel !== ''
            ? $originButtonLabel
            : $this->originButtonContent($platform, $originButtonUrl, $platformLabel);
        $originButtonAttrs = ['class' => 'share-embed__origin-btn'];
        if ($originButtonIsInternal === false) {
            $originButtonAttrs['rel'] = 'noopener noreferrer external nofollow';
            $originButtonAttrs['target'] = '_blank';
        }

        if ($isVideo) {
            $videoDurationRaw = (string)($data['videoDuration'] ?? $data['audioDuration'] ?? '');
            $videoDuration = $this->formatDuration($videoDurationRaw);
            if ($videoDuration === '' && array_key_exists('fallbackVideoDuration', $data)) {
                $videoDuration = $this->formatDuration((string)$data['fallbackVideoDuration']);
            }
            if ($videoDuration === '' && trim($videoDurationRaw) !== '') {
                // Last-resort display to avoid an empty footer when parsing fails.
                $videoDuration = trim($videoDurationRaw);
            }
            $videoChannel = $this->cleanText((string)($data['authorName'] ?? $data['authorLabel'] ?? ''));
            if (
                $videoChannel !== '' &&
                in_array(strtolower($videoChannel), ['youtube', 'vimeo', '@youtube', '@vimeo'], true)
            ) {
                $videoChannel = '';
            }

            $videoTitle = $title;
            if ($videoTitle === '') {
                $videoTitle = Str::short($this->cleanText((string)($data['description'] ?? '')), 120);
            }
            if ($videoTitle === '') {
                $videoTitle = $hostLabel !== '' ? $hostLabel : (($platform === 'vimeo') ? 'Vimeo-Video' : 'YouTube-Video');
            }
            $headerHost = $hostLabel !== '' ? $hostLabel : (($platform === 'vimeo') ? 'vimeo.com' : 'youtube.com');

            $embedSrc = null;
            if ($platform === 'youtube' && $youtubeId !== null) {
                $embedSrc = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($youtubeId) . '?autoplay=1&rel=0';
            } elseif ($platform === 'vimeo' && $vimeoId !== null) {
                $embedSrc = 'https://player.vimeo.com/video/' . rawurlencode($vimeoId) . '?autoplay=1';
            }

            $headMeta = [];
            $networkIcon = $this->networkIconSvg($platform);
            if ($networkIcon !== null) {
                $headMeta[] = Html::tag('span', [$networkIcon], array_filter([
                    'class' => in_array($platform, ['youtube', 'vimeo'], true)
                        ? 'share-embed__watchlog-icon share-embed__watchlog-icon--video'
                        : 'share-embed__network-icon',
                    'aria-hidden' => 'true',
                ]));
            } elseif (is_string($faviconLocal) && $faviconLocal !== '') {
                $headMeta[] = Html::img($faviconLocal, [
                    'class' => 'share-embed__favicon',
                    'alt' => '',
                    'loading' => 'lazy',
                    'decoding' => 'async',
                    'width' => 16,
                    'height' => 16,
                ]);
            }
            $headMeta[] = Html::span([
                Html::span($headerHost, ['class' => 'share-embed__host']),
            ], ['class' => 'share-embed__podcast-headtext']);

            $mediaHtml = '';
            if (!empty($data['imageLocalUrl']) && is_string($data['imageLocalUrl'])) {
                $mediaParts = [
                    Html::img($data['imageLocalUrl'], [
                        'alt' => $title,
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'class' => 'share-embed__image',
                    ]),
                ];

                if ($embedSrc !== null) {
                    $mediaParts[] = Html::tag('button', 'Play', [
                        'type' => 'button',
                        'class' => 'share-embed__play-btn js-share-yt-load',
                        'data-embed-src' => $embedSrc,
                        'data-embed-title' => $title !== '' ? $title : 'Video',
                        'aria-label' => 'Video im Fenster laden',
                    ]);
                    $mediaParts[] = Html::div([
                        Html::a('javascript:void(0)', 'Video laden', [
                            'class' => 'share-embed__overlay-btn js-share-yt-load',
                            'data-embed-src' => $embedSrc,
                            'data-embed-title' => $title !== '' ? $title : 'Video',
                            'role' => 'button',
                            'aria-label' => 'Video im Fenster laden',
                        ]),
                        Html::a('/datenschutz', 'Datenschutz', [
                            'class' => 'share-embed__overlay-midlink',
                        ]),
                    ], ['class' => 'share-embed__overlay']);
                }

                $mediaHtml = Html::div($mediaParts, ['class' => 'share-embed__media-wrap']);
            }

            $metaParts = [];
            if ($published !== null) {
                $metaParts[] = Html::span([
                    Html::tag('span', [$this->calendarIconSvg()], ['class' => 'share-embed__meta-icon', 'aria-hidden' => 'true']),
                    Html::span($published, array_filter([
                        'class' => 'share-embed__date share-embed__meta-text dt-published',
                        'datetime' => $publishedIso,
                    ])),
                ]);
            }
            if ($videoDuration !== '') {
                $metaParts[] = Html::span([
                    Html::tag('span', [$this->durationIconSvg()], ['class' => 'share-embed__meta-icon', 'aria-hidden' => 'true']),
                    Html::span($videoDuration, ['class' => 'share-embed__meta-text']),
                ], ['class' => 'share-embed__audio-duration']);
            }

            if (empty($metaParts) && $videoDuration === '') {
                $fallbackDurationRaw = trim((string)($data['fallbackVideoDuration'] ?? $data['videoDuration'] ?? $data['audioDuration'] ?? ''));
                $fallbackDuration = $this->formatDuration($fallbackDurationRaw);
                if ($fallbackDuration === '' && $fallbackDurationRaw !== '') {
                    $fallbackDuration = $fallbackDurationRaw;
                }
                if ($fallbackDuration !== '') {
                    $metaParts[] = Html::span([
                        Html::tag('span', [$this->durationIconSvg()], ['class' => 'share-embed__meta-icon', 'aria-hidden' => 'true']),
                        Html::span($fallbackDuration, ['class' => 'share-embed__meta-text']),
                    ], ['class' => 'share-embed__audio-duration']);
                }
            }

            $videoCardClass = 'share-embed share-embed--video h-cite';
            if ($mediaHtml !== '') {
                $videoCardClass .= ' share-embed--has-media';
            }

            return Html::tag(
                'figure',
                [Html::figcaption([
                    $this->microformatRelationLink($platform, $sourceUrl),
                    Html::header($headMeta, ['class' => 'share-embed__head share-embed__head--web']),
                    $mediaHtml,
                    Html::div([
                        $videoChannel !== '' ? Html::p($videoChannel, ['class' => 'share-embed__video-channel']) : '',
                        Html::h3([
                            Html::span($videoTitle, ['class' => 'share-embed__title-link p-name']),
                        ], ['class' => 'share-embed__title share-embed__title--web']),
                    ], ['class' => 'share-embed__video-content']),
                    Html::footer([
                        Html::div($metaParts, ['class' => 'share-embed__meta']),
                        Html::a(
                            $originButtonUrl,
                            $originButtonLabel !== '' ? $originButtonLabel : 'Video ansehen',
                            array_merge($originButtonAttrs, ['class' => 'share-embed__origin-btn share-embed__origin-btn--video'])
                        ),
                    ], ['class' => 'share-embed__footer']),
                ], ['class' => 'share-embed__body share-embed__body--video'])],
                [
                    'class' => $videoCardClass,
                    'data-share-embed' => 'true',
                    'data-share-url' => $sourceUrl,
                    'data-share-render-version' => '2026-03-05c',
                    'data-share-meta-published' => $published ?? '',
                    'data-share-meta-duration' => $videoDuration,
                    'role' => 'link',
                    'tabindex' => 0,
                    'aria-label' => 'Zum Ursprungsbeitrag',
                ]
            );
        }

        if ($platform === 'web') {
            $isPocketCasts = $this->isPocketCastsUrl($sourceUrl);
            $podcastTitle = $this->cleanText((string)($data['audioPodcastTitle'] ?? ''));
            $episodeNumber = $this->cleanText($this->normalizePocketCastsEpisodeNumber((string)($data['audioEpisodeNumber'] ?? '')));
            $audioDuration = $this->formatDuration((string)($data['audioDuration'] ?? ''));
            $podcastUrl = $this->safeUrl((string)($data['audioPodcastUrl'] ?? ''));

            $headerTitle = $title !== '' ? $title : $hostLabel;
            if ($isPocketCasts && $podcastTitle !== '') {
                $headerTitle = $podcastTitle;
            }
            $headerLine = $isPocketCasts
                ? trim($headerTitle . ($episodeNumber !== '' ? ', ' . $episodeNumber : ''))
                : $hostLabel;
            $episodeTitle = $title;
            if ($isPocketCasts) {
                $episodeTitleFromUrl = $this->pocketCastsEpisodeTitleFromUrl($sourceUrl);
                if (
                    $episodeTitleFromUrl !== '' &&
                    ($episodeTitle === '' || strcasecmp($episodeTitle, $headerLine) === 0 || strcasecmp($episodeTitle, $hostLabel) === 0)
                ) {
                    $episodeTitle = $episodeTitleFromUrl;
                }
                if ($episodeNumber === '') {
                    $episodeNumber = $this->pocketCastsEpisodeNumberFromTitle($episodeTitle);
                }
            }

            $headMeta = [];
            if ($isPocketCasts) {
                if (is_string($faviconLocal) && $faviconLocal !== '') {
                    $podcastAvatar = Html::img($faviconLocal, [
                        'class' => 'share-embed__favicon share-embed__favicon--podcast',
                        'alt' => '',
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'width' => 40,
                        'height' => 40,
                    ]);
                    $headMeta[] = $podcastUrl
                        ? Html::a($podcastUrl, [$podcastAvatar], [
                            'class' => 'share-embed__podcast-link',
                            'rel' => 'noopener noreferrer external nofollow',
                            'target' => '_blank',
                        ])
                        : $podcastAvatar;
                } else {
                    $headMeta[] = Html::tag('span', [$this->audiofeedIconSvg()], [
                        'class' => 'share-embed__favicon share-embed__favicon--icon',
                        'aria-hidden' => 'true',
                    ]);
                }
                $headMeta[] = Html::div([
                    Html::span($headerTitle, ['class' => 'share-embed__podcast-title']),
                    $episodeNumber !== '' ? Html::span($episodeNumber, ['class' => 'share-embed__podcast-episode']) : '',
                ], ['class' => 'share-embed__podcast-headtext']);
                $subscribeButtonHtml = $this->renderPodloveSubscribeButton($data);
                if ($subscribeButtonHtml !== '') {
                    $headMeta[] = $subscribeButtonHtml;
                }
            } elseif (is_string($faviconLocal) && $faviconLocal !== '') {
                $headMeta[] = Html::img($faviconLocal, [
                    'class' => 'share-embed__favicon',
                    'alt' => '',
                    'loading' => 'lazy',
                    'decoding' => 'async',
                    'width' => 16,
                    'height' => 16,
                ]);
            }
            if ($isPocketCasts === false) {
                $headMeta[] = Html::span($headerLine, ['class' => 'share-embed__host']);
            }

            $webMedia = '';
            if (!empty($data['imageLocalUrl']) && is_string($data['imageLocalUrl'])) {
                $webMedia = Html::a($sourceUrl, [
                    Html::img($data['imageLocalUrl'], [
                        'alt' => $title,
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'class' => 'share-embed__image' . ($isPocketCasts ? ' share-embed__image--audiofeed' : ''),
                    ])
                ], [
                    'class' => 'share-embed__media-link' . ($isPocketCasts ? ' share-embed__media-link--audiofeed' : ''),
                    'rel' => 'noopener noreferrer external nofollow',
                    'target' => '_blank',
                ]);
            }

            $licenseText = $this->cleanText((string)($data['imageLicenseText'] ?? ''));
            $licenseUrl = $this->safeUrl((string)($data['imageLicenseUrl'] ?? ''));
            $licenseHtml = '';
            if ($webMedia !== '' && ($licenseText !== '' || $licenseUrl !== null)) {
                $licenseParts = [];
                $licenseParts[] = Html::span('Lizenzhinweis:', ['class' => 'share-embed__license-label']);
                if ($licenseUrl !== null) {
                    $licenseParts[] = Html::a(
                        $licenseUrl,
                        $licenseText !== '' ? $licenseText : 'Lizenz ansehen',
                        [
                            'class' => 'share-embed__license-link',
                            'target' => '_blank',
                            'rel' => 'noopener noreferrer external nofollow',
                        ]
                    );
                } elseif ($licenseText !== '') {
                    $licenseParts[] = Html::span($licenseText, ['class' => 'share-embed__license-text']);
                }
                $licenseHtml = Html::div($licenseParts, ['class' => 'share-embed__license']);
            }

            $publishedLabel = $isPocketCasts
                ? $this->formatPublishedDate($data['publishedTime'] ?? null, false)
                : $this->formatPublishedDate($data['publishedTime'] ?? null, true, true);

            $footerLine = [];
            if ($publishedLabel !== null) {
                $footerLine[] = Html::span([
                    Html::tag('span', [$this->calendarIconSvg()], ['class' => 'share-embed__meta-icon', 'aria-hidden' => 'true']),
                    Html::span($publishedLabel, array_filter([
                        'class' => 'share-embed__date dt-published',
                        'datetime' => $publishedIso,
                    ])),
                ]);
            }

            $webParts = [];
            $webParts[] = $this->microformatRelationLink($platform, $sourceUrl);
            $webParts[] = Html::span([
                Html::a($sourceUrl, $hostLabel, [
                    'class' => 'p-name u-url',
                    'rel' => 'noopener noreferrer external nofollow',
                    'target' => '_blank',
                ]),
            ], ['class' => 'p-author h-card visually-hidden']);
            $webParts[] = Html::header($headMeta, ['class' => 'share-embed__head share-embed__head--web']);

            $titleHtml = Html::tag('h3', [
                Html::a($sourceUrl, ($isPocketCasts ? ($episodeTitle !== '' ? $episodeTitle : $headerLine) : ($title !== '' ? $title : $hostLabel)), [
                    'class' => 'share-embed__title-link p-name',
                    'rel' => 'noopener noreferrer external nofollow',
                    'target' => '_blank',
                ])
            ], ['class' => 'share-embed__title share-embed__title--web']);

            if ($isPocketCasts === false) {
                $webParts[] = $titleHtml;
            }

            if ($webMedia !== '') {
                $webParts[] = $webMedia;
                if ($licenseHtml !== '') {
                    $webParts[] = $licenseHtml;
                }
            }
            if ($isPocketCasts === true) {
                $webParts[] = $titleHtml;
            }
            if ($description !== '' && $isPocketCasts === false) {
                $webParts[] = Html::tag(
                    'blockquote',
                    [$this->renderTextWithLinks($description)],
                    ['class' => 'share-embed__description share-embed__description--quote e-content']
                );
            }

            if ($isPocketCasts && $audioDuration !== '') {
                $footerLine[] = Html::span([
                    Html::tag('span', [$this->durationIconSvg()], ['class' => 'share-embed__meta-icon', 'aria-hidden' => 'true']),
                    Html::span($audioDuration, ['class' => 'share-embed__meta-text']),
                ], ['class' => 'share-embed__audio-duration']);
            }

                $webParts[] = Html::footer([
                    Html::div([implode(' · ', $footerLine)], ['class' => 'share-embed__meta']),
                    Html::a($originButtonUrl, $originButtonContent, $originButtonAttrs),
                ], ['class' => 'share-embed__footer']);

            $webCardClass = 'share-embed share-embed--web h-cite';
            if ($isPocketCasts) {
                $webCardClass .= ' share-embed--audiofeed';
            }
            if ($webMedia !== '') {
                $webCardClass .= ' share-embed--has-media';
            }

            return Html::tag(
                'figure',
                [Html::figcaption($webParts, ['class' => 'share-embed__body share-embed__body--web'])],
                [
                    'class' => $webCardClass,
                    'data-share-embed' => 'true',
                    'data-share-url' => $sourceUrl,
                    'role' => 'link',
                    'tabindex' => 0,
                    'aria-label' => 'Zum Ursprungsbeitrag',
                ]
            );
        }

        $mediaHtml = '';
        if (!empty($data['imageLocalUrl']) && is_string($data['imageLocalUrl'])) {
            $mediaHtml = Html::a($sourceUrl, [
                Html::img($data['imageLocalUrl'], [
                    'alt' => $title,
                    'loading' => 'lazy',
                    'decoding' => 'async',
                    'class' => 'share-embed__image',
                ])
            ], [
                'class' => 'share-embed__media-link',
                'rel' => 'noopener noreferrer external nofollow',
                'target' => '_blank',
            ]);
        }

        $displayName = $authorName !== '' ? $authorName : $this->guessDisplayName($title, $authorLabel);
        $handle = $authorLabel !== '' ? $authorLabel : $platformLabel;
        $handleDisplay = $this->handleDisplay($platform, $handle);

        $identityLinkUrl = $authorUrl ?? $sourceUrl;
        $avatar = '';
        if (is_string($avatarLocal) && $avatarLocal !== '') {
            $avatar = Html::a($identityLinkUrl, [
                Html::img($avatarLocal, [
                    'class' => 'share-embed__avatar u-photo',
                    'alt' => $authorLabel !== '' ? $authorLabel : $hostLabel,
                    'loading' => 'lazy',
                    'decoding' => 'async',
                    'width' => 44,
                    'height' => 44,
                ])
            ], [
                'class' => 'share-embed__avatar-link',
                'target' => '_blank',
                'rel' => 'me noopener noreferrer external nofollow',
            ]);
        }

        $identity = [];
        if ($displayName !== '') {
            $identityAttrs = [
                'class' => 'share-embed__author p-author h-card p-name u-url',
                'target' => '_blank',
                'rel' => 'me noopener noreferrer external nofollow',
            ];
            if ($authorNameHtml !== '') {
                $identity[] = Html::tag('a', [$authorNameHtml], array_merge($identityAttrs, ['href' => $identityLinkUrl]));
            } else {
                $identity[] = Html::a($identityLinkUrl, $displayName, $identityAttrs);
            }
        }
        if ($handleDisplay !== '') {
            $networkIcon = $this->networkIconSvg($platform);
            if ($networkIcon !== null) {
                $identity[] = Html::tag('span', [
                    Html::tag('span', [$networkIcon], ['class' => 'share-embed__network-icon', 'aria-hidden' => 'true']),
                    Html::span($handleDisplay, ['class' => 'share-embed__network-text p-nickname']),
                ], ['class' => 'share-embed__network']);
            } else {
                $identity[] = Html::span($handleDisplay, ['class' => 'share-embed__network p-nickname']);
            }
        }

        $postText = $description !== '' ? $description : $title;
        $metaLeft = [];
        if ($published !== null) {
            $metaLeft[] = Html::span($published, array_filter([
                'class' => 'share-embed__date dt-published',
                'datetime' => $publishedIso,
            ]));
        }

        $bodyParts = [];
        $bodyParts[] = $this->microformatRelationLink($platform, $sourceUrl);
        $bodyParts[] = Html::span($citeName, ['class' => 'p-name visually-hidden']);
        $bodyParts[] = Html::header([
            $avatar,
            Html::div($identity, ['class' => 'share-embed__identity'])
        ], ['class' => 'share-embed__head']);
        if ($mediaHtml !== '') {
            $bodyParts[] = $mediaHtml;
        }
        if ($postText !== '') {
            $bodyParts[] = Html::p([$this->renderTextWithLinks($postText)], ['class' => 'share-embed__description e-content']);
        }
        $bodyParts[] = Html::footer([
            Html::div([implode(' · ', $metaLeft)], ['class' => 'share-embed__meta']),
            Html::a($originButtonUrl, $originButtonContent, $originButtonAttrs)
        ], ['class' => 'share-embed__footer']);

        return Html::tag(
            'figure',
            [Html::figcaption($bodyParts, ['class' => 'share-embed__body'])],
            [
                'class' => 'share-embed share-embed--social h-cite',
                'data-share-embed' => 'true',
                'data-share-url' => $sourceUrl,
                'role' => 'link',
                'tabindex' => 0,
                'aria-label' => 'Zum Ursprungsbeitrag',
            ]
        );
    }

    private function microformatRelationLink(string $platform, string $sourceUrl): string
    {
        return Html::a($sourceUrl, $sourceUrl, [
            'class' => 'u-url ' . $this->relationClassForPlatform($platform) . ' visually-hidden',
        ]);
    }

    private function fetchMetadata(string $url, array $platform): array
    {
        $platformId = (string)($platform['id'] ?? '');
        $headers = [
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
        ];
        $agent = 'hnzio share embed cache';
        if ($platformId === 'youtube') {
            $headers['Accept-Language'] = 'en-US,en;q=0.9';
            $headers['Cookie'] = 'CONSENT=YES+cb.20210328-17-p0.en+FX+667';
            $agent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
        }

        try {
            $response = Remote::get($url, [
                'timeout' => 15,
                'agent' => $agent,
                'headers' => $headers,
            ]);
        } catch (Throwable) {
            return [];
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return [];
        }

        $html = (string)$response->content();
        if ($html === '') {
            return [];
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $title = $this->firstFilled([
            $this->metaBy($xpath, 'property', 'og:title'),
            $this->metaBy($xpath, 'name', 'twitter:title'),
            $this->tagText($xpath, 'title'),
        ]);

        $description = $this->firstFilled([
            $this->metaBy($xpath, 'property', 'og:description'),
            $this->metaBy($xpath, 'name', 'description'),
            $this->metaBy($xpath, 'name', 'twitter:description'),
            $this->firstParagraph($xpath),
        ]);

        $canonical = $this->firstFilled([
            $this->metaBy($xpath, 'property', 'og:url'),
            $this->linkHref($xpath, 'canonical'),
        ]);
        $canonicalResolved = $this->absoluteUrl($canonical, $url) ?? $url;

        $pocketData = $this->pocketCastsStructuredData($canonicalResolved, $html);
        if (empty($pocketData)) {
            $pocketData = $this->pocketCastsStructuredData($url, $html);
        }

        $isGenericPocketTitle = in_array(
            strtolower($this->cleanText((string)$title)),
            ['discover - pocket casts', 'pocket casts'],
            true
        );
        if (trim((string)$title) === '' || $isGenericPocketTitle) {
            $title = $this->firstFilled([
                $pocketData['episodeTitle'] ?? null,
                $this->tagText($xpath, 'title'),
            ]);
        }

        $image = $this->firstFilled([
            $this->metaBy($xpath, 'property', 'og:image'),
            $this->metaBy($xpath, 'property', 'og:image:url'),
            $this->metaBy($xpath, 'name', 'twitter:image'),
            $this->metaBy($xpath, 'name', 'twitter:image:src'),
            $pocketData['episodeImage'] ?? null,
            $this->jsonLdImage($html),
            $this->firstContentImage($xpath),
        ]);

        $pocketEpisodeImage = $this->pocketCastsEpisodeImageFromHtml($canonicalResolved, $html);
        if ($pocketEpisodeImage === null) {
            $pocketEpisodeImage = $this->pocketCastsEpisodeImageFromHtml($url, $html);
        }
        if ($pocketEpisodeImage !== null) {
            $image = $pocketEpisodeImage;
        }

        $authorName = $this->firstFilled([
            $this->metaBy($xpath, 'property', 'author'),
            $this->metaBy($xpath, 'name', 'author'),
            $this->metaBy($xpath, 'itemprop', 'author'),
            $this->jsonLdAuthor($html),
        ]);

        $videoOembed = null;
        if (in_array(($platform['id'] ?? ''), ['youtube', 'vimeo'], true)) {
            $videoOembed = $this->videoOembed($url, (string)($platform['id'] ?? ''));
            if (is_array($videoOembed)) {
                $authorName = $this->firstFilled([
                    (string)($videoOembed['author_name'] ?? ''),
                    $authorName,
                ]);
                $title = $this->firstFilled([
                    (string)($videoOembed['title'] ?? ''),
                    $title,
                ]);
            }
        }
        $title = $this->normalizeVideoTitle((string)$title, (string)($platform['id'] ?? ''));

        $favicon = $this->firstFilled([
            $this->linkHref($xpath, 'icon'),
            $this->linkHref($xpath, 'shortcut icon'),
            $this->linkHref($xpath, 'apple-touch-icon'),
            $this->linkHref($xpath, 'mask-icon'),
        ]);

        $authorImage = $this->firstFilled([
            $this->metaBy($xpath, 'name', 'author:image'),
            $this->metaBy($xpath, 'property', 'profile:image'),
            $this->metaBy($xpath, 'property', 'og:profile:image'),
            $this->metaBy($xpath, 'name', 'twitter:creator:image'),
            $this->metaBy($xpath, 'name', 'parsely-author-image-url'),
        ]);

        $published = $this->firstPublished([
            $this->metaBy($xpath, 'property', 'article:published_time'),
            $this->metaBy($xpath, 'property', 'og:published_time'),
            $this->metaBy($xpath, 'name', 'pubdate'),
            $this->metaBy($xpath, 'name', 'date'),
            $this->metaBy($xpath, 'itemprop', 'datePublished'),
            $this->xpathText($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " date-text ")]'),
            $this->xpathText($xpath, '//*[@data-testid="episode-date"]'),
            $this->xpathText($xpath, '//*[contains(@class, "episode-date")]'),
            $this->xpathText($xpath, '//*[contains(@class, "published")]'),
            $this->jsonLdPublished($html),
            $pocketData['published'] ?? null,
            $this->publishedFromHtml($html),
            $this->timeDatetime($xpath),
        ]);
        if (($platform['id'] ?? '') === 'youtube' && $published === null) {
            $published = $this->youtubePublishedFromHtml($html);
        }

        $licenseHref = $this->firstFilled([
            $this->linkHref($xpath, 'license'),
            $this->metaBy($xpath, 'property', 'og:license'),
            $this->metaBy($xpath, 'name', 'license'),
        ]);

        $licenseText = $this->firstFilled([
            $this->metaBy($xpath, 'name', 'copyright'),
            $this->metaBy($xpath, 'property', 'og:copyright'),
            $this->metaBy($xpath, 'name', 'dc.rights'),
            $this->metaBy($xpath, 'name', 'dcterms.rights'),
            $this->metaBy($xpath, 'property', 'article:copyright'),
        ]);

        $pocketFromUrl = $this->pocketCastsPartsFromUrl($canonicalResolved);
        if ($pocketFromUrl === []) {
            $pocketFromUrl = $this->pocketCastsPartsFromUrl($url);
        }

        $pocketPodcastUrl = $this->pocketCastsPodcastPageUrl($canonicalResolved)
            ?? $this->pocketCastsPodcastPageUrl($url);
        if (
            $pocketPodcastUrl !== null &&
            (
                trim((string)($pocketData['podcastTitle'] ?? '')) === '' ||
                trim((string)($pocketData['podcastWebsiteUrl'] ?? '')) === '' ||
                trim((string)($pocketData['podcastFeedUrl'] ?? '')) === ''
            )
        ) {
            $podcastPageData = $this->fetchPocketCastsStructuredData($pocketPodcastUrl);
            if ($podcastPageData !== []) {
                $pocketData = array_merge($podcastPageData, $pocketData);
            }
        }

        $podcastTitle = $this->firstFilled([
            $this->xpathText($xpath, '//*[contains(@class, "podcast-title")]'),
            $this->xpathText($xpath, '//*[contains(@class, "podcast_title")]'),
            $this->xpathText($xpath, '//*[@data-testid="podcast-title"]'),
            $pocketData['podcastTitle'] ?? null,
            $pocketFromUrl['podcastTitle'] ?? null,
            $this->metaBy($xpath, 'property', 'og:site_name'),
        ]);

        $audioDuration = $this->firstFilled([
            $this->metaBy($xpath, 'itemprop', 'duration'),
            $this->metaBy($xpath, 'name', 'duration'),
            $this->xpathText($xpath, '//*[contains(@class, "duration")]'),
            $this->xpathText($xpath, '//*[contains(@class, "episode-duration")]'),
        ]);
        $videoDuration = $audioDuration;
        if (is_array($videoOembed) && isset($videoOembed['duration']) && is_numeric($videoOembed['duration'])) {
            $seconds = (int)$videoOembed['duration'];
            if ($seconds > 0) {
                $videoDuration = (string)$seconds;
            }
        }
        if (($platform['id'] ?? '') === 'youtube') {
            $youtubeDuration = $this->youtubeDurationFromHtml($html);
            if ($youtubeDuration !== null && trim((string)$youtubeDuration) !== '') {
                $videoDuration = $youtubeDuration;
            }

            $videoId = (string)($platform['videoId'] ?? '');
            if ($videoId !== '' && (($published === null) || trim((string)$videoDuration) === '')) {
                $youtubeInfo = $this->fetchYoutubeVideoInfo($videoId);
                if (is_array($youtubeInfo)) {
                    if ($published === null && isset($youtubeInfo['published'])) {
                        $normalizedPublished = $this->normalizePublishedValue((string)$youtubeInfo['published']);
                        if ($normalizedPublished !== null) {
                            $published = $normalizedPublished;
                        }
                    }
                    if (trim((string)$videoDuration) === '' && isset($youtubeInfo['duration'])) {
                        $videoDuration = (string)$youtubeInfo['duration'];
                    }
                }
            }

            $videoId = (string)($platform['videoId'] ?? '');
            if ($videoId !== '' && ($published === null || trim((string)$videoDuration) === '')) {
                $youtubeApi = $this->fetchYoutubeDataApi($videoId);
                if (is_array($youtubeApi)) {
                    if ($published === null && isset($youtubeApi['published'])) {
                        $normalizedPublished = $this->normalizePublishedValue((string)$youtubeApi['published']);
                        if ($normalizedPublished !== null) {
                            $published = $normalizedPublished;
                        }
                    }
                    if (trim((string)$videoDuration) === '' && isset($youtubeApi['duration'])) {
                        $videoDuration = (string)$youtubeApi['duration'];
                    }
                }
            }
        }

        $audioEpisodeNumber = $this->firstFilled([
            $this->xpathText($xpath, '//*[contains(@class, "numbering")]'),
            $this->xpathText($xpath, '//*[contains(@class, "episode-number")]'),
            $this->xpathText($xpath, '//*[contains(@class, "episode_number")]'),
            $pocketData['episodeNumber'] ?? null,
            $this->pocketCastsEpisodeNumberFromHtml($html),
            $pocketFromUrl['episodeNumber'] ?? null,
        ]);

        $normalizedAudioDuration = $this->normalizeDurationValue($audioDuration);
        $normalizedVideoDuration = $this->normalizeDurationValue((string)$videoDuration);
        if ($normalizedVideoDuration === '' && $normalizedAudioDuration !== '') {
            $normalizedVideoDuration = $normalizedAudioDuration;
        }

        $podcastWebsiteUrl = $this->safeUrl((string)($pocketData['podcastWebsiteUrl'] ?? ''));
        $audioFeedUrl = $this->safeUrl((string)($pocketData['podcastFeedUrl'] ?? ''));

        $heisePodcastWebsiteUrl = $this->inferHeisePodcastWebsiteUrl((string)($podcastWebsiteUrl ?? ''), $podcastTitle);
        if (
            $heisePodcastWebsiteUrl !== null &&
            (
                $this->isWeakHeisePodcastWebsiteUrl((string)($podcastWebsiteUrl ?? '')) ||
                $audioFeedUrl === null ||
                $this->isWeakHeisePodcastFeedUrl((string)($audioFeedUrl ?? ''))
            )
        ) {
            $heisePodcastFeedUrl = $this->discoverPodcastFeedUrl($heisePodcastWebsiteUrl);
            if ($heisePodcastFeedUrl !== null) {
                $podcastWebsiteUrl = $heisePodcastWebsiteUrl;
                $audioFeedUrl = $heisePodcastFeedUrl;
            } elseif ($podcastWebsiteUrl === null || $this->isWeakHeisePodcastWebsiteUrl((string)($podcastWebsiteUrl ?? ''))) {
                $podcastWebsiteUrl = $heisePodcastWebsiteUrl;
            }
        }

        if ($audioFeedUrl === null && $podcastWebsiteUrl !== null) {
            $audioFeedUrl = $this->discoverPodcastFeedUrl($podcastWebsiteUrl);
        }
        if ($audioFeedUrl === null) {
            $audioFeedUrl = $this->inferPocketCastsFeedUrlFromEpisodeAudio(
                (string)($pocketData['episodeAudioUrl'] ?? ''),
                $podcastTitle
            );
        }
        if ($audioFeedUrl === null) {
            $audioFeedUrl = $this->inferPocketCastsFeedUrlFromKnownShow(
                $podcastTitle,
                (string)($podcastWebsiteUrl ?? ''),
                $canonicalResolved ?? $url
            );
        }

        $audioEnclosureUrl = $this->safeUrl((string)($pocketData['episodeAudioUrl'] ?? ''));
        $audioEnclosureMimeType = $this->cleanText((string)($pocketData['episodeAudioMimeType'] ?? ''));
        $audioFeedFormat = $this->inferPodcastFeedFormat(
            $audioEnclosureMimeType,
            (string)($audioEnclosureUrl ?? ''),
            (string)($audioFeedUrl ?? '')
        );

        return [
            'title' => $title,
            'description' => $description,
            'canonicalUrl' => $canonicalResolved,
            'image' => $this->absoluteUrl($image, $url),
            'audioPodcastTitle' => $podcastTitle,
            'audioEpisodeNumber' => $audioEpisodeNumber,
            'audioPodcastWebsiteUrl' => $podcastWebsiteUrl,
            'audioFeedUrl' => $audioFeedUrl,
            'audioFeedFormat' => $audioFeedFormat,
            'audioDuration' => $normalizedAudioDuration,
            'videoDuration' => $normalizedVideoDuration,
            'imageLicenseText' => $licenseText,
            'imageLicenseUrl' => $this->absoluteUrl($licenseHref, $url),
            'favicon' => $this->absoluteUrl($favicon, $url),
            'authorImage' => $this->absoluteUrl($authorImage, $url),
            'authorName' => $authorName,
            'publishedTime' => $published,
        ];
    }

    private function inferHeisePodcastWebsiteUrl(string $currentWebsiteUrl, string $podcastTitle): ?string
    {
        $title = html_entity_decode(trim($podcastTitle), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($title === '') {
            return null;
        }

        $currentUrl = $this->safeUrl($currentWebsiteUrl);
        if ($currentUrl !== null) {
            $host = strtolower((string)(parse_url($currentUrl, PHP_URL_HOST) ?? ''));
            if ($host !== '' && $host !== 'www.heise.de' && $host !== 'heise.de') {
                return null;
            }
        }

        $baseTitle = trim((string)(preg_split('/\s+[–-]\s+/u', $title, 2)[0] ?? $title));
        if ($baseTitle === '') {
            return null;
        }

        $slug = preg_replace('~[^\pL\pN]+~u', '-', $baseTitle);
        $slug = trim((string)$slug, '-');
        if ($slug === '') {
            return null;
        }

        return 'https://www.heise.de/thema/' . rawurlencode($slug);
    }

    private function isWeakHeisePodcastWebsiteUrl(string $url): bool
    {
        $safeUrl = $this->safeUrl($url);
        if ($safeUrl === null) {
            return true;
        }

        $host = strtolower((string)(parse_url($safeUrl, PHP_URL_HOST) ?? ''));
        if ($host !== 'www.heise.de' && $host !== 'heise.de') {
            return false;
        }

        $path = strtolower((string)(parse_url($safeUrl, PHP_URL_PATH) ?? ''));
        if ($path === '' || $path === '/') {
            return true;
        }

        return preg_match('~^/(ct/impressum|newsletter/anmeldung\.html)(?:/|$)~i', $path) === 1;
    }

    private function isWeakHeisePodcastFeedUrl(string $url): bool
    {
        $safeUrl = $this->safeUrl($url);
        if ($safeUrl === null) {
            return true;
        }

        $host = strtolower((string)(parse_url($safeUrl, PHP_URL_HOST) ?? ''));
        if ($host !== 'www.heise.de' && $host !== 'heise.de') {
            return false;
        }

        $path = strtolower((string)(parse_url($safeUrl, PHP_URL_PATH) ?? ''));
        return $path === '/ct/feed.xml';
    }

    private function renderPodloveSubscribeButton(array $data): string
    {
        $podcastTitle = $this->cleanText((string)($data['audioPodcastTitle'] ?? ''));
        if ($podcastTitle === '') {
            return '';
        }

        $feedUrl = $this->safeUrl((string)($data['audioFeedUrl'] ?? ''));
        if ($feedUrl === null) {
            $feedUrl = $this->inferPocketCastsFeedUrlFromKnownShow(
                $podcastTitle,
                (string)($data['audioPodcastWebsiteUrl'] ?? ''),
                (string)($data['canonicalUrl'] ?? $data['audioPodcastUrl'] ?? $data['sourceUrl'] ?? '')
            );
        }
        if ($feedUrl === null) {
            return '';
        }

        $feedFormat = $this->cleanText((string)($data['audioFeedFormat'] ?? ''));
        if ($feedFormat === '') {
            $feedFormat = 'mp3';
        }

        $coverUrl = $this->safeUrl((string)($data['faviconLocalUrl'] ?? ''))
            ?? $this->safeUrl((string)($data['imageLocalUrl'] ?? ''))
            ?? $this->safeUrl((string)($data['faviconRemoteUrl'] ?? ''))
            ?? $this->safeUrl((string)($data['imageRemoteUrl'] ?? ''));
        $description = $this->cleanText((string)($data['description'] ?? ''));
        $hash = substr(sha1($feedUrl . '|' . $podcastTitle), 0, 12);
        $variable = 'hnzPodloveData' . $hash;

        $podcastData = [
            'title' => $podcastTitle,
            'subtitle' => '',
            'description' => $description,
            'cover' => $coverUrl ?? '',
            'feeds' => [[
                'type' => 'audio',
                'format' => $feedFormat,
                'url' => $feedUrl,
            ]],
        ];

        $json = json_encode(
            $podcastData,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if (!is_string($json) || $json === '') {
            return '';
        }

        $scriptUrl = url('assets/podlove-subscribe-button/javascripts/app.js');

        return '<script>window.' . $variable . '=' . $json . ';</script>'
            . Html::tag('span', [
                Html::tag('span', '', [
                    'class' => 'js-podlove-subscribe-config',
                    'src' => $scriptUrl,
                    'data-language' => 'de',
                    'data-size' => 'medium',
                    'data-format' => 'square',
                    'data-style' => 'filled',
                    'data-color' => '#5b63ff',
                    'data-json-data' => $variable,
                ]),
                Html::tag('noscript', Html::a($feedUrl, 'Abonnieren', [
                    'class' => 'share-embed__subscribe-fallback',
                    'target' => '_blank',
                    'rel' => 'noopener noreferrer external nofollow',
                ])),
            ], ['class' => 'share-embed__subscribe-slot']);
    }

    private function videoOembed(string $url, string $platformId): ?array
    {
        $endpoint = null;
        if ($platformId === 'youtube') {
            $endpoint = 'https://www.youtube.com/oembed?format=json&url=' . rawurlencode($url);
        } elseif ($platformId === 'vimeo') {
            $endpoint = 'https://vimeo.com/api/oembed.json?url=' . rawurlencode($url);
        }

        if ($endpoint === null) {
            return null;
        }

        try {
            $response = Remote::get($endpoint, [
                'timeout' => 12,
                'agent' => 'hnzio share embed cache',
                'headers' => [
                    'Accept' => 'application/json,*/*;q=0.5',
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return null;
        }

        $json = $response->json(true);
        return is_array($json) ? $json : null;
    }

    private function fetchProfileImageCandidate(string $profileUrl): ?string
    {
        $meta = $this->fetchMetadata($profileUrl, []);
        return $this->safeUrl((string)($meta['authorImage'] ?? $meta['image'] ?? ''));
    }

    private function fetchBlueskyAvatar(string $handle): ?string
    {
        if ($handle === '') {
            return null;
        }

        $endpoint = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . rawurlencode($handle);

        try {
            $response = Remote::get($endpoint, [
                'timeout' => 12,
                'agent' => 'hnzio share embed cache',
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return null;
        }

        $json = $response->json(true);
        if (!is_array($json)) {
            return null;
        }

        return $this->safeUrl((string)($json['avatar'] ?? ''));
    }

    private function fetchMastodonProfile(string $host, string $user): ?array
    {
        $host = strtolower(trim($host));
        $user = trim($user);
        if ($host === '' || $user === '' || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            return null;
        }

        $candidates = [
            $user,
            $user . '@' . $host,
        ];

        foreach ($candidates as $acct) {
            $endpoint = 'https://' . $host . '/api/v1/accounts/lookup?acct=' . rawurlencode($acct);

            try {
                $response = Remote::get($endpoint, [
                    'timeout' => 12,
                    'agent' => 'hnzio share embed cache',
                    'headers' => ['Accept' => 'application/json'],
                ]);
            } catch (Throwable) {
                continue;
            }

            if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
                continue;
            }

            $json = $response->json(true);
            if (!is_array($json)) {
                continue;
            }

            return [
                'displayName' => $this->cleanText((string)($json['display_name'] ?? '')),
                'displayNameRaw' => (string)($json['display_name'] ?? ''),
                'avatar' => (string)($json['avatar'] ?? ''),
                'acct' => (string)($json['acct'] ?? ''),
                'username' => (string)($json['username'] ?? ''),
                'url' => (string)($json['url'] ?? ''),
                'emojis' => is_array($json['emojis'] ?? null) ? $json['emojis'] : [],
            ];
        }

        return null;
    }

    private function renderDisplayNameWithCustomEmojis(string $displayName, array $emojis, string $seed): string
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            return '';
        }

        $emojiMap = [];
        foreach ($emojis as $emoji) {
            if (!is_array($emoji)) {
                continue;
            }

            $shortcode = trim((string)($emoji['shortcode'] ?? ''));
            if ($shortcode === '') {
                continue;
            }

            $remote = $this->safeUrl((string)($emoji['static_url'] ?? $emoji['url'] ?? ''));
            if ($remote === null) {
                continue;
            }

            $local = $this->downloadImage($remote, sha1($seed . ':masto-emoji:' . strtolower($shortcode)));
            if ($local === null) {
                continue;
            }

            $emojiMap[strtolower($shortcode)] = [
                'url' => $local,
                'label' => ':' . $shortcode . ':',
            ];
        }

        if ($emojiMap === []) {
            return Html::encode($this->cleanText($displayName));
        }

        $parts = preg_split('/(:[a-z0-9_]+:)/i', $displayName, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return Html::encode($this->cleanText($displayName));
        }

        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^:([a-z0-9_]+):$/i', $part, $m) === 1) {
                $key = strtolower($m[1]);
                $meta = $emojiMap[$key] ?? null;
                if (is_array($meta)) {
                    $out .= Html::img((string)$meta['url'], [
                        'class' => 'share-embed__name-emoji',
                        'alt' => (string)$meta['label'],
                        'loading' => 'lazy',
                        'decoding' => 'async',
                        'width' => 18,
                        'height' => 18,
                        'style' => 'width:.9em!important;height:.9em!important;max-width:.9em!important;min-width:.9em!important;object-fit:contain!important;',
                    ]);
                    continue;
                }
            }

            $out .= Html::encode($part);
        }

        return $out;
    }

    private function fetchMastodonAvatarFromStatus(string $statusUrl): ?string
    {
        $host = strtolower((string)(parse_url($statusUrl, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host);
        $path = (string)(parse_url($statusUrl, PHP_URL_PATH) ?? '');
        if ($host === '' || $path === '') {
            return null;
        }

        $statusId = null;
        if (preg_match('~/@[^/]+/(\d+)~', $path, $m) === 1) {
            $statusId = $m[1];
        } elseif (preg_match('~/users/[^/]+/statuses/(\d+)~', $path, $m) === 1) {
            $statusId = $m[1];
        }

        if ($statusId === null) {
            return null;
        }

        $endpoint = 'https://' . $host . '/api/v1/statuses/' . rawurlencode($statusId);

        try {
            $response = Remote::get($endpoint, [
                'timeout' => 12,
                'agent' => 'hnzio share embed cache',
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return null;
        }

        $json = $response->json(true);
        if (!is_array($json)) {
            return null;
        }

        return $this->safeUrl((string)($json['account']['avatar'] ?? ''));
    }

    private function fetchBlueskyProfile(string $actor): ?array
    {
        if ($actor === '') {
            return null;
        }

        $endpoint = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . rawurlencode($actor);

        try {
            $response = Remote::get($endpoint, [
                'timeout' => 12,
                'agent' => 'hnzio share embed cache',
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return null;
        }

        $json = $response->json(true);
        if (!is_array($json)) {
            return null;
        }

        return [
            'handle' => (string)($json['handle'] ?? ''),
            'displayName' => (string)($json['displayName'] ?? ''),
            'avatar' => (string)($json['avatar'] ?? ''),
        ];
    }

    private function detectPlatform(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '/');
        $hostNoWww = preg_replace('/^www\./', '', $host);

        if (in_array($hostNoWww, ['youtube.com', 'youtu.be', 'm.youtube.com'], true)) {
            $videoId = $this->youtubeId($url);
            return [
                'id' => 'youtube',
                'label' => 'YouTube',
                'authorLabel' => null,
                'authorUrl' => null,
                'videoId' => $videoId,
                'fallbackTitle' => 'YouTube-Video',
                'fallbackAuthor' => 'YouTube',
            ];
        }

        if (in_array($hostNoWww, ['vimeo.com', 'player.vimeo.com'], true)) {
            return [
                'id' => 'vimeo',
                'label' => 'Vimeo',
                'authorLabel' => null,
                'authorUrl' => null,
                'fallbackTitle' => 'Vimeo-Video',
                'fallbackAuthor' => 'Vimeo',
            ];
        }

        if ($hostNoWww === 'bsky.app' && preg_match('~^/profile/([^/]+)~', $path, $m) === 1) {
            $handle = strtolower($m[1]);
            return [
                'id' => 'bluesky',
                'label' => 'Bluesky',
                'authorLabel' => '@' . $handle . '@bluesky',
                'authorUrl' => 'https://bsky.app/profile/' . rawurlencode($handle),
                'fallbackTitle' => 'Bluesky-Beitrag',
                'fallbackAuthor' => '@' . $handle . '@bluesky',
            ];
        }

        if (
            preg_match('~^/@([^/]+)~', $path, $m) === 1 ||
            preg_match('~^/users/([^/]+)/statuses~', $path, $m) === 1
        ) {
            $user = $m[1];
            return [
                'id' => 'mastodon',
                'label' => 'Mastodon',
                'mastodonHost' => $hostNoWww,
                'mastodonUser' => $user,
                'authorLabel' => '@' . $user . '@' . $hostNoWww,
                'authorUrl' => 'https://' . $hostNoWww . '/@' . rawurlencode($user),
                'fallbackTitle' => 'Mastodon-Beitrag',
                'fallbackAuthor' => '@' . $user . '@' . $hostNoWww,
            ];
        }

        $label = $hostNoWww !== '' ? $hostNoWww : 'Website';
        return [
            'id' => 'web',
            'label' => $label,
            'authorLabel' => $label,
            'authorUrl' => $hostNoWww !== '' ? 'https://' . $hostNoWww : null,
            'fallbackTitle' => $label,
            'fallbackAuthor' => $label,
        ];
    }

    private function youtubeId(string $url): ?string
    {
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = trim((string)($parts['path'] ?? ''), '/');

        if ($host === 'youtu.be' && $path !== '') {
            $candidate = explode('/', $path)[0] ?? '';
            if (preg_match('/^[a-zA-Z0-9_-]{11}$/', (string)$candidate) === 1) {
                return $candidate;
            }
        }

        parse_str((string)($parts['query'] ?? ''), $query);
        if (!empty($query['v']) && is_string($query['v'])) {
            $candidate = trim((string)$query['v']);
            if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        if (preg_match('~^(?:shorts|embed|live|v)/([a-zA-Z0-9_-]{11})(?:$|[/?#])~', $path, $m) === 1) {
            return (string)$m[1];
        }

        if (preg_match('~([a-zA-Z0-9_-]{11})~', (string)$url, $m) === 1) {
            return (string)$m[1];
        }

        return null;
    }

    private function vimeoId(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if ($host === '' || $path === '') {
            return null;
        }

        if (preg_match('~/(?:video/)?(\d{6,12})(?:$|[/?#])~', $path, $m) === 1) {
            return (string)($m[1] ?? '');
        }

        return null;
    }

    private function downloadImage(string $imageUrl, string $key): ?string
    {
        if (V::url($imageUrl) !== true) {
            return null;
        }

        try {
            $response = Remote::get($imageUrl, [
                'timeout' => 20,
                'agent' => 'hnzio share embed image cache',
                'headers' => ['Accept' => 'image/*,*/*;q=0.8'],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return null;
        }

        $binary = (string)$response->content();
        if ($binary === '') {
            return null;
        }

        $headers = [];
        foreach ($response->headers() as $name => $value) {
            $headers[strtolower((string)$name)] = (string)$value;
        }

        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
        $ext = match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => '',
        };

        if ($ext === '') {
            $fromPath = strtolower(pathinfo((string)parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
            $ext = in_array($fromPath, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'svg', 'ico'], true) ? $fromPath : 'jpg';
        }

        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $filename = $key . '.' . $ext;
        $filePath = $this->imageDir() . '/' . $filename;
        if ($this->safeWrite($filePath, $binary) === false) {
            return null;
        }

        return $this->assetsUrl() . '/images/' . $filename;
    }

    private function metaBy(DOMXPath $xpath, string $attr, string $name): ?string
    {
        $query = sprintf('//meta[translate(@%s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $attr, strtolower($name));
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            return trim((string)$nodes->item(0)?->nodeValue);
        }
        return null;
    }

    private function linkHref(DOMXPath $xpath, string $rel): ?string
    {
        $query = sprintf('//link[contains(translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "%s")]/@href', strtolower($rel));
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            return trim((string)$nodes->item(0)?->nodeValue);
        }
        return null;
    }

    private function tagText(DOMXPath $xpath, string $tag): ?string
    {
        $nodes = $xpath->query('//' . $tag);
        if ($nodes && $nodes->length > 0) {
            return trim((string)$nodes->item(0)?->textContent);
        }
        return null;
    }

    private function firstParagraph(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//article//p | //main//p | //p');
        if (!$nodes) {
            return null;
        }

        foreach ($nodes as $node) {
            $text = $this->cleanText((string)$node->textContent);
            if (mb_strlen($text) >= 24) {
                return $text;
            }
        }

        return null;
    }

    private function firstContentImage(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//article//img[@src] | //main//img[@src] | //img[@src]');
        if (!$nodes) {
            return null;
        }

        foreach ($nodes as $node) {
            $src = trim((string)$node->getAttribute('src'));
            if ($src === '') {
                continue;
            }

            $lower = strtolower($src);
            if (
                str_contains($lower, 'favicon') ||
                str_contains($lower, 'avatar') ||
                str_contains($lower, 'icon') ||
                str_contains($lower, 'logo')
            ) {
                continue;
            }

            $class = strtolower((string)$node->getAttribute('class'));
            if (
                str_contains($class, 'avatar') ||
                str_contains($class, 'icon') ||
                str_contains($class, 'logo')
            ) {
                continue;
            }

            $w = (int)$node->getAttribute('width');
            $h = (int)$node->getAttribute('height');
            if (($w > 0 && $w < 220) || ($h > 0 && $h < 140)) {
                continue;
            }

            return $src;
        }

        return null;
    }

    private function readCache(string $cachePath): ?array
    {
        $json = F::read($cachePath);
        if (!$json) {
            return null;
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function isFresh(array $cached): bool
    {
        if (($cached['schemaVersion'] ?? null) !== self::SCHEMA_VERSION) {
            return false;
        }

        $ttl = (int)$this->pluginOption('ttl', 60 * 60 * 24 * 30);
        $fetchedAt = $cached['fetchedAt'] ?? null;
        if (!is_string($fetchedAt)) {
            return false;
        }
        $ts = strtotime($fetchedAt);
        if ($ts === false) {
            return false;
        }
        return (time() - $ts) < $ttl;
    }

    private function cachePath(string $sourceUrl): string
    {
        return $this->cacheDir() . '/' . sha1(trim($sourceUrl)) . '.json';
    }

    private function ensureStorage(): void
    {
        try {
            Dir::make($this->baseDir());
            Dir::make($this->cacheDir());
            Dir::make($this->imageDir());
            $this->ensureStorageProtection();
        } catch (Throwable) {
            // On restricted hosts we continue without local caching.
        }
    }

    private function ensureStorageProtection(): void
    {
        $htaccessPath = $this->baseDir() . '/.htaccess';
        $htaccess = <<<HTACCESS
Options -Indexes
<IfModule mod_headers.c>
Header set X-Robots-Tag "noindex, noimageindex, noarchive"
</IfModule>
HTACCESS;
        if (F::exists($htaccessPath) === false) {
            $this->safeWrite($htaccessPath, $htaccess . "\n");
        }

        $robotsPath = $this->baseDir() . '/robots.txt';
        $robots = "User-agent: *\nDisallow: /\n";
        if (F::exists($robotsPath) === false) {
            $this->safeWrite($robotsPath, $robots);
        }
    }

    private function safeWrite(string $path, string $content): bool
    {
        try {
            F::write($path, $content);
            return true;
        } catch (Throwable) {
            // Rendering should not fail if cache/image writes are blocked by filesystem permissions.
            return false;
        }
    }

    private function baseDir(): string
    {
        $root = trim((string)$this->pluginOption('storage.root', ''));
        if ($root !== '') {
            return rtrim($root, '/\\');
        }

        $relativePath = trim((string)$this->pluginOption('storage.path', 'assets/share-embed'));
        return $this->kirby->root('index') . '/' . trim($relativePath, '/\\');
    }

    private function cacheDir(): string
    {
        return $this->baseDir() . '/data';
    }

    private function imageDir(): string
    {
        return $this->baseDir() . '/images';
    }

    private function assetsUrl(): string
    {
        $url = trim((string)$this->pluginOption('storage.url', ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        $relativePath = trim((string)$this->pluginOption('storage.path', 'assets/share-embed'));
        return rtrim($this->kirby->url('index'), '/') . '/' . trim($relativePath, '/');
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = strip_tags($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function normalizeVideoTitle(string $title, string $platform): string
    {
        $title = $this->cleanText($title);
        if ($title === '' || in_array($platform, ['youtube', 'vimeo'], true) === false) {
            return $title;
        }

        $title = preg_replace('/\s*[-|]\s*YouTube\s*$/iu', '', $title) ?? $title;
        $title = preg_replace('/\s*[-|]\s*Vimeo\s*$/iu', '', $title) ?? $title;
        $trimmed = trim($title, " \t\n\r\0\x0B-–—|");
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^(youtube|vimeo)$/iu', $trimmed) === 1) {
            return '';
        }

        return $trimmed;
    }

    private function absoluteUrl(?string $url, string $base): ?string
    {
        if (!is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        if (V::url($url) === true) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($base, PHP_URL_HOST) ?: '';
            if ($host === '') {
                return null;
            }
            return $scheme . '://' . $host . $url;
        }

        $dir = Url::currentDir();
        $baseParts = parse_url($base);
        if (!empty($baseParts['scheme']) && !empty($baseParts['host'])) {
            $basePath = (string)($baseParts['path'] ?? '/');
            $baseDir = rtrim(dirname($basePath), '/');
            $dir = $baseParts['scheme'] . '://' . $baseParts['host'] . ($baseDir === '' ? '' : $baseDir);
        }

        return rtrim($dir, '/') . '/' . ltrim($url, '/');
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        return V::url($url) ? $url : null;
    }

    private function defaultFaviconUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        if (!$host) {
            return null;
        }
        return $scheme . '://' . $host . '/favicon.ico';
    }

    private function hostLabel(string $url): string
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host);
        return $host !== '' ? $host : 'Website';
    }

    private function blueskyActorFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('~^/profile/([^/]+)~', $path, $m) === 1) {
            return strtolower(trim($m[1]));
        }

        return null;
    }

    private function extractBlueskyHandleFromText(string $text): ?string
    {
        if (preg_match('/@([a-z0-9][a-z0-9.-]+\.[a-z]{2,})/i', $text, $m) === 1) {
            return strtolower($m[1]);
        }
        return null;
    }

    private function handleDisplay(string $platform, string $handle): string
    {
        $handle = trim($handle);
        if ($handle === '') {
            return '';
        }

        if ($platform === 'bluesky') {
            if (preg_match('/^@([^@]+)@bluesky$/i', $handle, $m) === 1) {
                return '@' . $m[1];
            }
            if (preg_match('/^@([^@]+)@bsky\.social$/i', $handle, $m) === 1) {
                return '@' . $m[1];
            }
        }

        return $handle;
    }

    private function renderTextWithLinks(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $parts = preg_split('/(https?:\/\/[^\s<]+)/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return Html::encode($text);
        }

        $out = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/^https?:\/\/[^\s<]+$/iu', $part) === 1) {
                $cleanUrl = rtrim($part, ".,;:!?)]}");
                $out .= Html::a($cleanUrl, Url::short($cleanUrl, 24), [
                    'class' => 'share-embed__text-link',
                    'rel' => 'noopener noreferrer external nofollow',
                    'target' => '_blank',
                ]);
                continue;
            }

            $out .= Html::encode($part);
        }

        return $out;
    }

    private function originButtonText(string $platform, string $sourceUrl, string $platformLabel): string
    {
        return match ($platform) {
            'bluesky' => 'Auf Bluesky ansehen',
            'mastodon' => 'Auf Mastodon ansehen',
            'web' => $this->isPocketCastsUrl($sourceUrl) ? 'Folge anhören' : ('Auf ' . $this->hostLabel($sourceUrl) . ' ansehen'),
            default => 'Beitrag anzeigen',
        };
    }

    private function relationClassForPlatform(string $platform): string
    {
        return match ($platform) {
            'mastodon', 'bluesky' => 'u-repost-of',
            'web', 'youtube' => 'u-bookmark-of',
            default => 'u-mention-of',
        };
    }

    private function toIsoDateTime(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{10,13}$/', $value) === 1) {
            $ts = (int)$value;
            if (strlen($value) === 13) {
                $ts = (int)floor($ts / 1000);
            }
        } else {
            $ts = strtotime($value);
        }

        if ($ts === false) {
            return null;
        }

        return date(DATE_ATOM, $ts);
    }

    private function originButtonContent(string $platform, string $sourceUrl, string $platformLabel): array|string
    {
        $text = $this->originButtonText($platform, $sourceUrl, $platformLabel);
        $icon = $this->networkIconSvg($platform);
        if ($icon === null) {
            return $text;
        }

        return [
            Html::tag('span', [$icon], ['class' => 'share-embed__origin-icon', 'aria-hidden' => 'true']),
            Html::span($text, ['class' => 'share-embed__origin-text']),
        ];
    }

    private function networkIconSvg(string $platform): ?string
    {
        $filename = match ($platform) {
            'bluesky' => 'bluesky.svg',
            'mastodon' => 'mastodon.svg',
            'youtube', 'vimeo' => 'watchlog-meta.svg',
            default => null,
        };

        if ($filename === null) {
            return null;
        }

        $candidates = [
            rtrim((string)$this->pluginOption('icons.path', __DIR__ . '/icons'), '/\\') . '/' . $filename,
            $this->kirby->root('index') . '/site/snippets/icons/' . str_replace('.svg', '.php', $filename),
        ];

        foreach ($candidates as $path) {
            if (F::exists($path) === false) {
                continue;
            }

            $svg = trim((string)F::read($path));
            if ($svg === '' || str_starts_with($svg, '<svg') === false) {
                continue;
            }

            return $svg;
        }

        return null;
    }

    private function guessDisplayName(string $title, string $fallbackHandle): string
    {
        $title = trim($title);

        if ($title !== '' && !preg_match('/^@[^@\s]+@[^@\s]+$/', $title)) {
            if (preg_match('/^(.*?)\s*\(@[^)]+\)\s*$/u', $title, $m) === 1) {
                $title = trim($m[1]);
            }

            if ($title !== '' && $title !== $fallbackHandle) {
                return $title;
            }
        }

        return $fallbackHandle;
    }

    private function formatPublishedDate(mixed $value, bool $includeTime = true, bool $omitMidnightTime = false): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{10,13}$/', $value) === 1) {
            $ts = (int)$value;
            if (strlen($value) === 13) {
                $ts = (int)floor($ts / 1000);
            }
        } else {
            $ts = strtotime($value);
        }

        if ($ts === false) {
            return null;
        }

        $months = [
            1 => 'Jan.', 2 => 'Feb.', 3 => 'Mär.', 4 => 'Apr.', 5 => 'Mai', 6 => 'Jun.',
            7 => 'Jul.', 8 => 'Aug.', 9 => 'Sep.', 10 => 'Okt.', 11 => 'Nov.', 12 => 'Dez.',
        ];

        $day = (int)date('j', $ts);
        $month = $months[(int)date('n', $ts)] ?? date('M.', $ts);
        $year = date('Y', $ts);
        if ($includeTime === false) {
            return $day . '. ' . $month . ' ' . $year;
        }

        $time = date('H:i', $ts);
        if ($omitMidnightTime === true && $time === '00:00') {
            return $day . '. ' . $month . ' ' . $year;
        }
        return $day . '. ' . $month . ' ' . $year . ', ' . $time . ' Uhr';
    }

    private function timeDatetime(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//time[@datetime]/@datetime');
        if ($nodes && $nodes->length > 0) {
            return trim((string)$nodes->item(0)?->nodeValue);
        }
        return null;
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return null;
    }

    private function firstPublished(array $values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizePublishedValue($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return null;
    }

    private function normalizePublishedValue(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{10,13}$/', $value) === 1) {
            $ts = (int)$value;
            if (strlen($value) === 13) {
                $ts = (int)floor($ts / 1000);
            }
            return $ts > 0 ? date(DATE_ATOM, $ts) : null;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date(DATE_ATOM, $ts);
    }

    private function xpathText(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);
        if ($nodes && $nodes->length > 0) {
            return trim((string)$nodes->item(0)?->textContent);
        }
        return null;
    }

    private function isPocketCastsUrl(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $host = preg_replace('/^www\./', '', $host);
        return $host === 'pocketcasts.com';
    }

    private function formatDuration(?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', $raw, $m) === 1) {
            $h = (int)($m[1] ?? 0);
            $min = (int)($m[2] ?? 0);
            $sec = (int)($m[3] ?? 0);
            return $this->formatDurationFromSeconds(($h * 3600) + ($min * 60) + $sec);
        }

        if (preg_match('/^\s*(\d{1,3}):(\d{2})(?::(\d{2}))?\s*$/', $raw, $m) === 1) {
            $a = (int)$m[1];
            $b = (int)$m[2];
            $c = isset($m[3]) ? (int)$m[3] : null;
            if ($c !== null) {
                // h:mm:ss
                return $this->formatDurationFromSeconds(($a * 3600) + ($b * 60) + $c);
            }
            // mm:ss
            return $this->formatDurationFromSeconds(($a * 60) + $b);
        }

        if (preg_match('/^\s*(\d+)\s*hours?\s+(\d+)\s*minutes?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds((((int)$m[1]) * 3600) + (((int)$m[2]) * 60));
        }
        if (preg_match('/^\s*(\d+)\s*h(?:ours?)?\s+(\d+)\s*m(?:in(?:utes?)?)?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds((((int)$m[1]) * 3600) + (((int)$m[2]) * 60));
        }
        if (preg_match('/^\s*(\d+)\s*std\.?\s+(\d+)\s*min\.?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds((((int)$m[1]) * 3600) + (((int)$m[2]) * 60));
        }
        if (preg_match('/^\s*(\d+)\s*hours?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds(((int)$m[1]) * 3600);
        }
        if (preg_match('/^\s*(\d+)\s*hrs?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds(((int)$m[1]) * 3600);
        }
        if (preg_match('/^\s*(\d+)\s*h(?:ours?)?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds(((int)$m[1]) * 3600);
        }
        if (preg_match('/^\s*(\d+)\s*std\.?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds(((int)$m[1]) * 3600);
        }
        if (preg_match('/^\s*(\d+)\s*minutes?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds(((int)$m[1]) * 60);
        }
        if (preg_match('/^\s*(\d+)\s*mins?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds(((int)$m[1]) * 60);
        }
        if (preg_match('/^\s*(\d+)\s*m(?:in(?:utes?)?)?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds(((int)$m[1]) * 60);
        }
        if (preg_match('/^\s*(\d+)\s*min\.?\s*$/i', $raw, $m) === 1) {
            return $this->formatDurationFromSeconds(((int)$m[1]) * 60);
        }
        if (preg_match('/^\d+$/', $raw) === 1) {
            return $this->formatDurationFromSeconds((int)$raw);
        }

        return $raw;
    }

    private function normalizeDurationValue(?string $raw): string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return '';
        }

        $formatted = $this->formatDuration($raw);
        if ($formatted === '') {
            return '';
        }

        if (
            preg_match('/^\d+h \d+m$/', $formatted) === 1 ||
            preg_match('/^\d+m$/', $formatted) === 1
        ) {
            return $formatted;
        }

        return '';
    }

    private function hasRequiredCacheFields(array $cached): bool
    {
        $platform = strtolower(trim((string)($cached['platform'] ?? '')));
        if ($platform === 'youtube') {
            $published = $this->normalizePublishedValue($cached['publishedTime'] ?? null);
            $duration = $this->normalizeDurationValue((string)($cached['videoDuration'] ?? $cached['audioDuration'] ?? ''));
            return $published !== null && $duration !== '';
        }

        if ($platform === 'web') {
            $sourceUrl = trim((string)($cached['sourceUrl'] ?? ''));
            if ($this->isPocketCastsUrl($sourceUrl)) {
                $title = $this->cleanText((string)($cached['title'] ?? ''));
                $podcastTitle = $this->cleanText((string)($cached['audioPodcastTitle'] ?? ''));
                $episodeNumber = $this->cleanText((string)($cached['audioEpisodeNumber'] ?? ''));
                $imageRemote = $this->safeUrl((string)($cached['imageRemoteUrl'] ?? ''));
                $imageLocal = $this->safeUrl((string)($cached['imageLocalUrl'] ?? ''));

                $genericTitle = in_array(strtolower($title), ['discover - pocket casts', 'pocket casts'], true);
                $hasEpisodeText = $title !== '' && $genericTitle === false && $podcastTitle !== '';
                $hasEpisodeImage = $imageRemote !== null || $imageLocal !== null;

                // Pocket Casts can briefly serve generic HTML; treat that cache as incomplete so it gets retried.
                if ($hasEpisodeText === false) {
                    return false;
                }
                if ($hasEpisodeImage === false && $episodeNumber !== '') {
                    return false;
                }
            }
        }

        return true;
    }

    private function shouldRetryIncompleteCache(array $cached): bool
    {
        $fetchedAt = $cached['fetchedAt'] ?? null;
        if (!is_string($fetchedAt) || trim($fetchedAt) === '') {
            return true;
        }

        $ts = strtotime($fetchedAt);
        if ($ts === false) {
            return true;
        }

        $retryTtl = (int)$this->pluginOption(
            'incomplete-retry-ttl',
            $this->pluginOption('incompleteRetryTtl', 60 * 15)
        );
        return (time() - $ts) >= max(60, $retryTtl);
    }

    private function mergeWithCachedFallbacks(array $data, array $cached): array
    {
        if (strtolower((string)($data['platform'] ?? '')) !== 'youtube') {
            return $data;
        }

        if ($this->normalizePublishedValue($data['publishedTime'] ?? null) === null) {
            $cachedPublished = $this->normalizePublishedValue($cached['publishedTime'] ?? null);
            if ($cachedPublished !== null) {
                $data['publishedTime'] = $cachedPublished;
            }
        }

        $duration = $this->normalizeDurationValue((string)($data['videoDuration'] ?? $data['audioDuration'] ?? ''));
        if ($duration === '') {
            $cachedDuration = $this->normalizeDurationValue((string)($cached['videoDuration'] ?? $cached['audioDuration'] ?? ''));
            if ($cachedDuration !== '') {
                $data['videoDuration'] = $cachedDuration;
            }
        }

        return $data;
    }

    private function formatDurationFromSeconds(int $seconds): string
    {
        if ($seconds <= 0) {
            return '';
        }

        // Keep the label compact and consistent (`4h 22m` / `22m`).
        $minutesTotal = max(1, (int)round($seconds / 60));
        $hours = intdiv($minutesTotal, 60);
        $minutes = $minutesTotal % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }

    private function youtubeDurationFromHtml(string $html): ?string
    {
        if (preg_match('/"lengthSeconds":"(\d{1,6})"/', $html, $m) === 1) {
            return (string)$m[1];
        }
        if (preg_match('/"lengthSeconds":(\d{1,6})/', $html, $m) === 1) {
            return (string)$m[1];
        }
        if (preg_match('/"length_seconds":"(\d{1,6})"/', $html, $m) === 1) {
            return (string)$m[1];
        }
        if (preg_match('/"approxDurationMs":"(\d{3,9})"/', $html, $m) === 1) {
            $seconds = (int)round(((int)$m[1]) / 1000);
            if ($seconds > 0) {
                return (string)$seconds;
            }
        }
        return null;
    }

    private function youtubePublishedFromHtml(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        $patterns = [
            '/"publishDate"\s*:\s*"([^"]+)"/i',
            '/"uploadDate"\s*:\s*"([^"]+)"/i',
            '/"datePublished"\s*:\s*"([^"]+)"/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                $value = trim((string)($m[1] ?? ''));
                $normalized = $this->normalizePublishedValue($value);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function fetchYoutubeVideoInfo(string $videoId): ?array
    {
        $videoId = trim($videoId);
        if ($videoId === '' || preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId) !== 1) {
            return null;
        }

        $endpoint = 'https://www.youtube.com/get_video_info?video_id=' . rawurlencode($videoId) . '&el=detailpage&hl=en';

        try {
            $response = Remote::get($endpoint, [
                'timeout' => 12,
                'agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                'headers' => [
                    'Accept' => 'text/plain,*/*;q=0.5',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Cookie' => 'CONSENT=YES+cb.20210328-17-p0.en+FX+667',
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return null;
        }

        $raw = trim((string)$response->content());
        if ($raw === '') {
            return null;
        }

        $params = [];
        parse_str($raw, $params);
        if (!is_array($params) || $params === []) {
            return null;
        }

        $playerResponseRaw = (string)($params['player_response'] ?? '');
        if ($playerResponseRaw === '') {
            return null;
        }

        $playerResponse = json_decode($playerResponseRaw, true);
        if (!is_array($playerResponse)) {
            return null;
        }

        $duration = (string)($playerResponse['videoDetails']['lengthSeconds'] ?? '');
        $published = (string)($playerResponse['microformat']['playerMicroformatRenderer']['publishDate'] ?? '');
        if ($published === '') {
            $published = (string)($playerResponse['microformat']['playerMicroformatRenderer']['uploadDate'] ?? '');
        }

        $out = [];
        if (trim($duration) !== '') {
            $out['duration'] = trim($duration);
        }
        if (trim($published) !== '') {
            $out['published'] = trim($published);
        }

        return $out !== [] ? $out : null;
    }

    private function fetchYoutubeDataApi(string $videoId): ?array
    {
        $videoId = trim($videoId);
        if ($videoId === '' || preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId) !== 1) {
            return null;
        }

        $apiKey = trim((string)$this->pluginOption(
            'youtube.apiKey',
            $this->kirby->option('hnzio.youtube.api-key', $this->kirby->option('hnz.youtube.api-key', ''))
        ));
        if ($apiKey === '') {
            return null;
        }

        $endpoint = 'https://www.googleapis.com/youtube/v3/videos'
            . '?part=snippet,contentDetails'
            . '&id=' . rawurlencode($videoId)
            . '&key=' . rawurlencode($apiKey);

        try {
            $response = Remote::get($endpoint, [
                'timeout' => 12,
                'agent' => 'hnzio share embed cache',
                'headers' => [
                    'Accept' => 'application/json,*/*;q=0.5',
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return null;
        }

        $json = $response->json(true);
        if (!is_array($json)) {
            return null;
        }

        $items = $json['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return null;
        }

        $item = $items[0];
        if (!is_array($item)) {
            return null;
        }

        $published = (string)($item['snippet']['publishedAt'] ?? '');
        $duration = (string)($item['contentDetails']['duration'] ?? '');

        $out = [];
        if (trim($published) !== '') {
            $out['published'] = trim($published);
        }
        if (trim($duration) !== '') {
            $out['duration'] = trim($duration);
        }

        return $out !== [] ? $out : null;
    }

    private function audiofeedIconSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M320 64C178.6 64 64 178.6 64 320C64 461.4 178.6 576 320 576C461.4 576 576 461.4 576 320C576 178.6 461.4 64 320 64zM264 216C264 185.1 289.1 160 320 160C350.9 160 376 185.1 376 216L376 360C376 390.9 350.9 416 320 416C289.1 416 264 390.9 264 360L264 216zM208 272C221.3 272 232 282.7 232 296L232 360C232 408.6 271.4 448 320 448C368.6 448 408 408.6 408 360L408 296C408 282.7 418.7 272 432 272C445.3 272 456 282.7 456 296L456 360C456 429.9 403.5 487.6 336 495.2L336 536L392 536C405.3 536 416 546.7 416 560C416 573.3 405.3 584 392 584L248 584C234.7 584 224 573.3 224 560C224 546.7 234.7 536 248 536L304 536L304 495.2C236.5 487.6 184 429.9 184 360L184 296C184 282.7 194.7 272 208 272z"/></svg>';
    }

    private function durationIconSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M320 96C196.3 96 96 196.3 96 320C96 443.7 196.3 544 320 544C443.7 544 544 443.7 544 320C544 196.3 443.7 96 320 96zM320 48C470.2 48 592 169.8 592 320C592 470.2 470.2 592 320 592C169.8 592 48 470.2 48 320C48 169.8 169.8 48 320 48zM320 176C333.3 176 344 186.7 344 200L344 309.4L414.6 380C424 389.4 424 404.6 414.6 414C405.2 423.4 390 423.4 380.6 414L303 336.4C298.5 331.9 296 325.8 296 319.4L296 200C296 186.7 306.7 176 320 176z"/></svg>';
    }

    private function calendarIconSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M176 80C189.3 80 200 90.7 200 104L200 128L440 128L440 104C440 90.7 450.7 80 464 80C477.3 80 488 90.7 488 104L488 128L504 128C539.3 128 568 156.7 568 192L568 496C568 531.3 539.3 560 504 560L136 560C100.7 560 72 531.3 72 496L72 192C72 156.7 100.7 128 136 128L152 128L152 104C152 90.7 162.7 80 176 80zM120 256L120 496C120 504.8 127.2 512 136 512L504 512C512.8 512 520 504.8 520 496L520 256L120 256zM136 176C127.2 176 120 183.2 120 192L120 208L520 208L520 192C520 183.2 512.8 176 504 176L136 176z"/></svg>';
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string)$value));
        return in_array($value, ['1', 'true', 'yes', 'ja'], true);
    }

    private function isWebImageAllowed(string $sourceUrl, string $imageUrl, string $licenseText, ?string $licenseUrl): bool
    {
        if ($this->isPocketCastsUrl($sourceUrl)) {
            return true;
        }

        $safeMode = $this->isTruthy($this->pluginOption('web-image-safe-mode', true));
        if ($safeMode === false) {
            return true;
        }

        $allowedHosts = $this->normalizeHostList($this->pluginOption('web-image-allowed-hosts', []));
        $sourceHost = $this->normalizeHost((string)(parse_url($sourceUrl, PHP_URL_HOST) ?? ''));
        $imageHost = $this->normalizeHost((string)(parse_url($imageUrl, PHP_URL_HOST) ?? ''));
        if (($sourceHost !== '' && in_array($sourceHost, $allowedHosts, true)) || ($imageHost !== '' && in_array($imageHost, $allowedHosts, true))) {
            return true;
        }

        $requireLicense = $this->isTruthy($this->pluginOption('web-image-require-license', true));
        if ($requireLicense === false) {
            return true;
        }

        return $licenseText !== '' || is_string($licenseUrl);
    }

    private function normalizeHostList(mixed $value): array
    {
        $list = is_array($value) ? $value : [];
        $out = [];
        foreach ($list as $item) {
            $host = $this->normalizeHost((string)$item);
            if ($host !== '') {
                $out[] = $host;
            }
        }
        return array_values(array_unique($out));
    }

    private function jsonLdImage(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches) < 1) {
            return null;
        }

        foreach (($matches[1] ?? []) as $chunk) {
            $json = trim((string)$chunk);
            if ($json === '') {
                continue;
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }

            $found = $this->findImageInJsonLd($decoded);
            if (is_string($found) && trim($found) !== '') {
                return trim($found);
            }
        }

        return null;
    }

    private function jsonLdPublished(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches) < 1) {
            return null;
        }

        foreach (($matches[1] ?? []) as $chunk) {
            $json = trim((string)$chunk);
            if ($json === '') {
                continue;
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }

            $found = $this->findPublishedInJsonLd($decoded);
            if (is_string($found) && trim($found) !== '') {
                return trim($found);
            }
        }

        return null;
    }

    private function jsonLdAuthor(string $html): ?string
    {
        if ($html === '') {
            return null;
        }

        if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches) < 1) {
            return null;
        }

        foreach (($matches[1] ?? []) as $chunk) {
            $json = trim((string)$chunk);
            if ($json === '') {
                continue;
            }

            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }

            $found = $this->findAuthorInJsonLd($decoded);
            if (is_string($found) && trim($found) !== '') {
                return trim($found);
            }
        }

        return null;
    }

    private function findAuthorInJsonLd(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        foreach (['author', 'creator', 'publisher', 'channelName', 'ownerChannelName'] as $key) {
            if (!array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                if (isset($value['name']) && is_string($value['name']) && trim((string)$value['name']) !== '') {
                    return trim((string)$value['name']);
                }
                foreach ($value as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return trim($item);
                    }
                    $nested = $this->findAuthorInJsonLd($item);
                    if ($nested !== null) {
                        return $nested;
                    }
                }
            }
        }

        foreach ($node as $value) {
            $nested = $this->findAuthorInJsonLd($value);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function findPublishedInJsonLd(mixed $node): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        foreach (['datePublished', 'uploadDate', 'dateCreated', 'publishedAt', 'published_at', 'published'] as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && trim($node[$key]) !== '') {
                return trim($node[$key]);
            }
        }

        foreach ($node as $value) {
            $nested = $this->findPublishedInJsonLd($value);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function publishedFromHtml(string $html): ?string
    {
        $patterns = [
            '/"(?:datePublished|publishedAt|published_at|publishedDate|publishDate|releaseDate|release_date|pubDate)"\s*:\s*"([^"]+)"/i',
            '/"published"\s*:\s*"([^"]+)"/i',
            '/"(?:publishedAt|published_at|published|timestamp)"\s*:\s*(\d{10,13})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                $value = trim((string)($m[1] ?? ''));
                if ($value === '') {
                    continue;
                }

                if (preg_match('/^\d{10,13}$/', $value) === 1) {
                    $ts = (int)$value;
                    if (strlen($value) === 13) {
                        $ts = (int)floor($ts / 1000);
                    }
                    if ($ts > 0) {
                        return date(DATE_ATOM, $ts);
                    }
                    continue;
                }

                if (strtotime($value) !== false) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function pocketCastsStructuredData(string $url, string $html): array
    {
        if (!$this->isPocketCastsUrl($url) || $html === '') {
            return [];
        }

        $episodeId = $this->pocketCastsEpisodeIdFromUrl($url);
        $episodeSlug = $this->pocketCastsEpisodeSlugFromUrl($url);
        $jsonBlobs = [];

        if (preg_match('/<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)<\/script>/is', $html, $m) === 1) {
            $jsonBlobs[] = trim((string)($m[1] ?? ''));
        }

        if (preg_match_all('/<script[^>]+type=["\']application\/json["\'][^>]*>(.*?)<\/script>/is', $html, $m2) >= 1) {
            foreach (($m2[1] ?? []) as $blob) {
                $jsonBlobs[] = trim((string)$blob);
            }
        }

        $data = [];
        foreach ($jsonBlobs as $json) {
            if ($json === '') {
                continue;
            }

            $decoded = json_decode(html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($decoded)) {
                continue;
            }

            $candidate = $this->extractPocketCastsFieldsFromNode($decoded, $episodeId, $episodeSlug);
            $data = array_merge($data, array_filter($candidate, static fn ($v) => is_string($v) && trim($v) !== ''));
        }

        $streamData = $this->pocketCastsStreamData($html, $episodeSlug);
        if ($streamData !== []) {
            $data = array_merge($data, $streamData);
        }

        return $data;
    }

    private function pocketCastsStreamData(string $html, string $episodeSlug = ''): array
    {
        $buffers = [];
        if (preg_match_all('/streamController\.enqueue\("((?:[^"\\\\]|\\\\.)*)"\)/s', $html, $matches) >= 1) {
            foreach (($matches[1] ?? []) as $blob) {
                $decoded = json_decode('"' . $blob . '"', true);
                if (!is_string($decoded) || trim($decoded) === '') {
                    $decoded = stripcslashes((string)$blob);
                }

                if (is_string($decoded) && trim($decoded) !== '') {
                    $buffers[] = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        if ($buffers === []) {
            return [];
        }

        $buffer = implode("\n", $buffers);
        $websiteUrl = $this->bestPocketCastsWebsiteUrlFromBuffer($buffer);
        $podcastFeedUrl = $this->firstPocketCastsFeedUrlFromBuffer($buffer);

        $episodeAudioUrl = '';
        $episodeAudioMimeType = '';
        if (
            $episodeSlug !== '' &&
            preg_match('/"' . preg_quote($episodeSlug, '/') . '","(https?:\/\/[^"]+)","file_type","([^"]+)"/u', $buffer, $m) === 1
        ) {
            $episodeAudioUrl = $this->normalizePocketCastsEpisodeAudioUrl((string)($m[1] ?? ''));
            $episodeAudioMimeType = $this->cleanText((string)($m[2] ?? ''));
        }

        if ($episodeAudioUrl === '') {
            $episodeAudioUrl = $this->firstPocketCastsEpisodeAudioUrlFromBuffer($buffer);
        }

        return array_filter([
            'podcastWebsiteUrl' => $websiteUrl,
            'podcastFeedUrl' => $podcastFeedUrl,
            'episodeAudioUrl' => $episodeAudioUrl,
            'episodeAudioMimeType' => $episodeAudioMimeType,
        ], static fn ($value) => is_string($value) && trim($value) !== '');
    }

    private function bestPocketCastsWebsiteUrlFromBuffer(string $buffer): string
    {
        if ($buffer === '' || preg_match_all('/https?:\/\/[^"\s<]+/u', $buffer, $matches) < 1) {
            return '';
        }

        $cleanedCandidates = array_map(
            fn ($candidate) => $this->cleanPocketCastsBufferUrlCandidate((string)$candidate),
            $matches[0] ?? []
        );
        $hostCounts = [];
        foreach ($cleanedCandidates as $candidate) {
            $url = $this->safeUrl($candidate);
            if ($url === null) {
                continue;
            }

            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
            if ($host !== '') {
                $hostCounts[$host] = ($hostCounts[$host] ?? 0) + 1;
            }
        }

        $bestUrl = '';
        $bestScore = PHP_INT_MIN;

        foreach ($cleanedCandidates as $candidate) {
            $url = $this->normalizePocketCastsWebsiteUrl($candidate);
            if ($url === '') {
                continue;
            }

            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
            $hostCount = (int)($hostCounts[$host] ?? 0);
            $score = $this->scorePocketCastsWebsiteUrl($url, $hostCount);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestUrl = $url;
            }
        }

        return $bestUrl;
    }

    private function firstPocketCastsFeedUrlFromBuffer(string $buffer): string
    {
        if ($buffer === '' || preg_match_all('/https?:\/\/[^"\s<]+/u', $buffer, $matches) < 1) {
            return '';
        }

        foreach (($matches[0] ?? []) as $candidate) {
            $url = $this->normalizePocketCastsFeedUrl($this->cleanPocketCastsBufferUrlCandidate((string)$candidate));
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function firstPocketCastsEpisodeAudioUrlFromBuffer(string $buffer): string
    {
        if ($buffer === '' || preg_match_all('/https?:\/\/[^"\s<]+/u', $buffer, $matches) < 1) {
            return '';
        }

        foreach (($matches[0] ?? []) as $candidate) {
            $url = $this->normalizePocketCastsEpisodeAudioUrl($this->cleanPocketCastsBufferUrlCandidate((string)$candidate));
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function cleanPocketCastsBufferUrlCandidate(string $candidate): string
    {
        $candidate = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $candidate = str_replace('\\/', '/', $candidate);
        $candidate = preg_split('/\\\\/u', $candidate, 2)[0] ?? $candidate;
        $candidate = preg_split('/[<>"\'\\]\\),]/u', $candidate, 2)[0] ?? $candidate;
        $candidate = trim($candidate);
        $candidate = trim($candidate, "\\\"',).]}");

        return $candidate;
    }

    private function scorePocketCastsWebsiteUrl(string $url, int $hostCount = 0): int
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
        $score = 0;

        if ($path !== '' && $path !== '/') {
            $score += 2;
        } else {
            $score -= 2;
        }

        if ($hostCount >= 3) {
            $score += min(12, $hostCount * 2);
        }

        if (($path === '' || $path === '/') && $hostCount >= 3) {
            $score += 4;
        }

        if (preg_match('~/(podcast|audio|show|shows|series|serie|thema|topic)~i', $path) === 1) {
            $score += 5;
        }

        if (preg_match('~\.(?:css|js|png|jpe?g|webp|avif|svg|ico|woff2?)$~i', $path) === 1) {
            $score -= 20;
        }

        if (preg_match('~/(support|donate|spende|impressum|imprint|newsletter|anmeldung|about|ueber|uber|privacy|datenschutz|terms|policy|kontakt|contact|faq)(?:/|$)~i', $path) === 1) {
            $score -= 10;
        }

        if (preg_match('~/(podlove/(?:image|file)|media|files?|download)(?:/|$)~i', $path) === 1) {
            $score -= 8;
        }

        if (str_contains($host, 'wonderl.ink')) {
            $score -= 3;
        }

        return $score;
    }

    private function extractPocketCastsFieldsFromNode(mixed $node, ?string $episodeId = null, string $episodeSlug = ''): array
    {
        if (!is_array($node)) {
            return [];
        }

        $podcastWebsiteUrl = $this->normalizePocketCastsWebsiteUrl($this->findValueByKeys($node, [
            'websiteUrl', 'website_url', 'siteUrl', 'site_url', 'webUrl', 'web_url', 'url',
        ], null));
        $podcastFeedUrl = $this->normalizePocketCastsFeedUrl($this->findValueByKeys($node, [
            'feedUrl', 'feed_url', 'rssUrl', 'rss_url', 'xmlUrl', 'xml_url', 'podcastFeedUrl', 'podcast_feed_url',
            'feedUri', 'feed_uri',
        ], null));

        if ($episodeId !== null) {
            $episodeNode = $this->findPocketEpisodeNodeById($node, $episodeId);
            if (is_array($episodeNode)) {
                $episodeImage = $this->findValueByKeys($episodeNode, [
                    'episodeImage', 'episode_image', 'episodeImageUrl', 'episode_image_url',
                    'episodeArtwork', 'episode_artwork', 'imageUrl', 'image_url', 'image',
                ], null);
                $episodeTitle = $this->findValueByKeys($episodeNode, [
                    'episodeTitle', 'episode_title', 'title',
                ], null);
                $episodeNumber = $this->findValueByKeys($episodeNode, [
                    'numbering', 'episodeNumber', 'episode_number', 'episodeCode', 'episode_code', 'shortTitle', 'short_title', 'identifier', 'code',
                ], null);
                $episodeAudioUrl = $this->findValueByKeys($episodeNode, [
                    'enclosureUrl', 'enclosure_url', 'audioUrl', 'audio_url', 'fileUrl', 'file_url', 'url',
                ], null);
                $episodeAudioMimeType = $this->findValueByKeys($episodeNode, [
                    'fileType', 'file_type', 'mimeType', 'mime_type',
                ], null);
                $published = $this->findValueByKeys($episodeNode, [
                    'publishedAt', 'published_at', 'publishedDate', 'publishDate', 'releaseDate', 'release_date', 'datePublished', 'published', 'publishedTimestamp', 'published_timestamp', 'publishedAtMs', 'published_at_ms',
                ], null);

                $podcastTitle = $this->findValueByKeys($node, [
                    'podcastTitle', 'podcast_title', 'showTitle', 'show_title', 'podcastName', 'podcast_name',
                ], null);

                return array_filter([
                    'episodeImage' => $this->cleanText((string)$episodeImage) !== '' ? (string)$episodeImage : '',
                    'episodeTitle' => $this->cleanText((string)$episodeTitle),
                    'episodeNumber' => $this->cleanText((string)$episodeNumber),
                    'episodeAudioUrl' => $this->normalizePocketCastsEpisodeAudioUrl($episodeAudioUrl),
                    'episodeAudioMimeType' => $this->cleanText((string)$episodeAudioMimeType),
                    'podcastTitle' => $this->cleanText((string)$podcastTitle),
                    'podcastWebsiteUrl' => $podcastWebsiteUrl,
                    'podcastFeedUrl' => $podcastFeedUrl,
                    'published' => is_string($published) ? trim($published) : '',
                ], static fn ($v) => is_string($v) && trim($v) !== '');
            }
        }

        $episodeImageRaw = $this->findValueByKeys($node, [
            'episodeImage', 'episode_image', 'episodeImageUrl', 'episode_image_url',
            'episodeArtwork', 'episode_artwork', 'imageUrl', 'image_url', 'image',
        ], $episodeId);
        $episodeImage = '';
        if (
            is_string($episodeImageRaw) &&
            $this->isEpisodeSpecificImageUrl($episodeImageRaw, $episodeId, $episodeSlug)
        ) {
            $episodeImage = $episodeImageRaw;
        }

        $episodeTitle = $this->findValueByKeys($node, [
            'episodeTitle', 'episode_title', 'title',
        ], $episodeId);

        $episodeNumber = $this->findValueByKeys($node, [
            'numbering', 'episodeNumber', 'episode_number', 'episodeCode', 'episode_code', 'shortTitle', 'short_title', 'identifier', 'code',
        ], $episodeId);

        $episodeAudioUrl = $this->findValueByKeys($node, [
            'enclosureUrl', 'enclosure_url', 'audioUrl', 'audio_url', 'fileUrl', 'file_url',
        ], $episodeId);
        $episodeAudioMimeType = $this->findValueByKeys($node, [
            'fileType', 'file_type', 'mimeType', 'mime_type',
        ], $episodeId);

        $podcastTitle = $this->findValueByKeys($node, [
            'podcastTitle', 'podcast_title', 'showTitle', 'show_title', 'podcastName', 'podcast_name',
        ], null);

        $published = $this->findValueByKeys($node, [
            'publishedAt', 'published_at', 'publishedDate', 'publishDate', 'releaseDate', 'release_date', 'datePublished', 'published', 'publishedTimestamp', 'published_timestamp', 'publishedAtMs', 'published_at_ms',
        ], $episodeId);

        return array_filter([
            'episodeImage' => $this->cleanText((string)$episodeImage) !== '' ? (string)$episodeImage : '',
            'episodeTitle' => $this->cleanText((string)$episodeTitle),
            'episodeNumber' => $this->cleanText((string)$episodeNumber),
            'episodeAudioUrl' => $this->normalizePocketCastsEpisodeAudioUrl($episodeAudioUrl),
            'episodeAudioMimeType' => $this->cleanText((string)$episodeAudioMimeType),
            'podcastTitle' => $this->cleanText((string)$podcastTitle),
            'podcastWebsiteUrl' => $podcastWebsiteUrl,
            'podcastFeedUrl' => $podcastFeedUrl,
            'published' => is_string($published) ? trim($published) : '',
        ], static fn ($v) => is_string($v) && trim($v) !== '');
    }

    private function normalizePocketCastsWebsiteUrl(?string $candidate): string
    {
        $url = $this->safeUrl((string)$candidate);
        if ($url === null) {
            return '';
        }

        if (
            $this->isPocketCastsInternalUrl($url) ||
            $this->isMetadataOrNamespaceUrl($url) ||
            $this->looksLikeWebAssetUrl($url) ||
            $this->isPocketCastsUrl($url) ||
            $this->looksLikeAudioFileUrl($url) ||
            $this->looksLikePodcastFeedUrl($url)
        ) {
            return '';
        }

        return $url;
    }

    private function normalizePocketCastsFeedUrl(?string $candidate): string
    {
        $url = $this->safeUrl((string)$candidate);
        if (
            $url === null ||
            $this->isPocketCastsInternalUrl($url) ||
            $this->looksLikeWebAssetUrl($url) ||
            $this->looksLikePodcastFeedUrl($url) === false
        ) {
            return '';
        }

        return $url;
    }

    private function normalizePocketCastsEpisodeAudioUrl(?string $candidate): string
    {
        $url = $this->safeUrl((string)$candidate);
        if ($url === null || $this->looksLikeAudioFileUrl($url) === false) {
            return '';
        }

        return $url;
    }

    private function looksLikePodcastFeedUrl(string $url): bool
    {
        if (
            $this->isPocketCastsInternalUrl($url) ||
            $this->isMetadataOrNamespaceUrl($url) ||
            $this->looksLikeWebAssetUrl($url) ||
            $this->looksLikeAudioFileUrl($url)
        ) {
            return false;
        }

        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
        $query = strtolower((string)(parse_url($url, PHP_URL_QUERY) ?? ''));
        $combined = trim($path . '?' . $query, '?');

        if ($combined === '') {
            return false;
        }

        if (preg_match('~(?:^|[/?._-])(rss|feed|atom)(?:[/?._-]|$)~i', $combined) === 1) {
            return true;
        }

        return preg_match('~\.(rss|xml|atom)$~i', $path) === 1;
    }

    private function isPocketCastsInternalUrl(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));

        if ($host === '') {
            return false;
        }

        if (
            $host === 'pca.st' ||
            str_ends_with($host, '.pca.st') ||
            $host === 'static.pocketcasts.com' ||
            str_ends_with($host, '.pocketcasts.com')
        ) {
            return true;
        }

        return str_contains($path, '/oembed');
    }

    private function isMetadataOrNamespaceUrl(string $url): bool
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            return false;
        }

        return in_array($host, [
            'w3.org',
            'www.w3.org',
            'schema.org',
            'www.schema.org',
            'ogp.me',
            'xmlns.com',
            'purl.org',
            'search.yahoo.com',
        ], true);
    }

    private function looksLikeWebAssetUrl(string $url): bool
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
        if ($path === '') {
            return false;
        }

        return preg_match('~\.(?:js|css|json|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|eot|pdf)$~i', $path) === 1;
    }

    private function looksLikeAudioFileUrl(string $url): bool
    {
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
        if ($path === '') {
            return false;
        }

        return preg_match('~\.(mp3|m4a|aac|ogg|oga|opus|wav)$~i', $path) === 1;
    }

    private function discoverPodcastFeedUrl(string $websiteUrl): ?string
    {
        $websiteUrl = $this->safeUrl($websiteUrl);
        if ($websiteUrl === null) {
            return null;
        }

        if ($this->looksLikePodcastFeedUrl($websiteUrl)) {
            return $websiteUrl;
        }

        try {
            $response = Remote::get($websiteUrl, [
                'timeout' => 12,
                'agent' => 'hnzio share embed cache',
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return null;
        }

        $html = (string)$response->content();
        if ($html === '') {
            return null;
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);

        $bestAlternateFeed = null;
        $bestAlternateScore = PHP_INT_MIN;
        foreach ($xpath->query('//link[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $rel = strtolower(trim((string)$link->getAttribute('rel')));
            $type = strtolower(trim((string)$link->getAttribute('type')));
            $title = strtolower(trim((string)$link->getAttribute('title')));
            if (str_contains($rel, 'alternate') === false) {
                continue;
            }
            if (
                str_contains($type, 'rss') === false &&
                str_contains($type, 'atom') === false &&
                str_contains($type, 'xml') === false &&
                str_contains($title, 'rss') === false &&
                str_contains($title, 'podcast') === false &&
                str_contains($title, 'feed') === false
            ) {
                continue;
            }

            $candidate = $this->absoluteUrl((string)$link->getAttribute('href'), $websiteUrl);
            if ($candidate === null) {
                continue;
            }

            if ($this->looksLikePodcastFeedUrl($candidate)) {
                return $candidate;
            }

            $score = 0;
            if (str_contains($title, 'podcast')) {
                $score += 6;
            }
            if (str_contains($title, 'audio')) {
                $score += 3;
            }
            if (str_contains($title, 'mp3')) {
                $score += 2;
            }
            if (str_contains($title, 'feed')) {
                $score += 1;
            }
            if (str_contains($type, 'rss') || str_contains($type, 'atom') || str_contains($type, 'xml')) {
                $score += 2;
            }
            if (str_contains($title, 'comment') || str_contains($title, 'kommentar')) {
                $score -= 10;
            }

            if ($score > $bestAlternateScore) {
                $bestAlternateScore = $score;
                $bestAlternateFeed = $candidate;
            }
        }

        if ($bestAlternateFeed !== null) {
            return $bestAlternateFeed;
        }

        $regexes = [
            '/"feedUrl"\s*:\s*"([^"]+)"/i',
            '/"rssUrl"\s*:\s*"([^"]+)"/i',
            '/"xmlUrl"\s*:\s*"([^"]+)"/i',
            '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(?:[^<]{0,60})<\/a>/i',
        ];

        foreach ($regexes as $regex) {
            if (preg_match_all($regex, $html, $matches) < 1) {
                continue;
            }

            foreach (($matches[1] ?? []) as $match) {
                $candidate = $this->absoluteUrl(html_entity_decode((string)$match, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $websiteUrl);
                if ($candidate !== null && $this->looksLikePodcastFeedUrl($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function fetchPocketCastsStructuredData(string $url): array
    {
        $url = $this->safeUrl($url);
        if ($url === null || $this->isPocketCastsUrl($url) === false) {
            return [];
        }

        $requestOptions = [
            'timeout' => 12,
            'agent' => 'hnzio share embed cache',
            'headers' => [
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
            ],
        ];

        try {
            $response = Remote::get($url, $requestOptions);
        } catch (Throwable) {
            return [];
        }

        if (($response->code() ?? 0) >= 300 && ($response->code() ?? 0) < 400) {
            $redirectUrl = $this->redirectLocationFromResponse($response, $url);
            if ($redirectUrl !== null && $this->isPocketCastsUrl($redirectUrl)) {
                $url = $redirectUrl;

                try {
                    $response = Remote::get($url, $requestOptions);
                } catch (Throwable) {
                    return [];
                }
            }
        }

        if (($response->code() ?? 0) < 200 || ($response->code() ?? 0) >= 400) {
            return [];
        }

        $html = (string)$response->content();
        if ($html === '') {
            return [];
        }

        $data = $this->pocketCastsStructuredData($html, $url);
        $bufferWebsiteUrl = $this->bestPocketCastsWebsiteUrlFromBuffer($html);
        $currentWebsiteUrl = $this->safeUrl((string)($data['podcastWebsiteUrl'] ?? ''));
        if (
            $bufferWebsiteUrl !== '' &&
            (
                $currentWebsiteUrl === null ||
                $this->scorePocketCastsWebsiteUrl($bufferWebsiteUrl) > $this->scorePocketCastsWebsiteUrl($currentWebsiteUrl)
            )
        ) {
            $data['podcastWebsiteUrl'] = $bufferWebsiteUrl;
        }
        if (trim((string)($data['podcastFeedUrl'] ?? '')) === '') {
            $data['podcastFeedUrl'] = $this->firstPocketCastsFeedUrlFromBuffer($html);
        }

        $episodeAudioUrl = trim((string)($data['episodeAudioUrl'] ?? ''));
        if ($episodeAudioUrl === '') {
            $episodeAudioUrl = $this->firstPocketCastsEpisodeAudioUrlFromBuffer($html);
            if ($episodeAudioUrl !== '') {
                $data['episodeAudioUrl'] = $episodeAudioUrl;
            }
        }

        $websiteUrl = $this->safeUrl((string)($data['podcastWebsiteUrl'] ?? ''));
        if (trim((string)($data['podcastFeedUrl'] ?? '')) === '' && $websiteUrl !== null) {
            $data['podcastFeedUrl'] = $this->discoverPodcastFeedUrl($websiteUrl) ?? '';
        }
        if (trim((string)($data['podcastFeedUrl'] ?? '')) === '') {
            $data['podcastFeedUrl'] = $this->inferPocketCastsFeedUrlFromEpisodeAudio(
                $episodeAudioUrl,
                (string)($data['podcastTitle'] ?? '')
            ) ?? '';
        }
        if (trim((string)($data['podcastFeedUrl'] ?? '')) === '') {
            $data['podcastFeedUrl'] = $this->inferPocketCastsFeedUrlFromKnownShow(
                (string)($data['podcastTitle'] ?? ''),
                (string)($data['podcastWebsiteUrl'] ?? ''),
                $url
            ) ?? '';
        }

        return array_filter($data, static fn ($value) => is_string($value) && trim($value) !== '');
    }

    private function redirectLocationFromResponse(object $response, string $baseUrl): ?string
    {
        $location = '';
        foreach ((array)$response->headers() as $name => $value) {
            if (strtolower((string)$name) === 'location') {
                $location = trim((string)$value);
                break;
            }
        }

        if ($location === '') {
            return null;
        }

        return $this->absoluteUrl($location, $baseUrl) ?? $this->safeUrl($location);
    }

    private function inferPocketCastsFeedUrlFromEpisodeAudio(string $episodeAudioUrl, string $podcastTitle): ?string
    {
        $episodeAudioUrl = $this->safeUrl($episodeAudioUrl);
        if ($episodeAudioUrl === null) {
            return null;
        }

        $host = strtolower((string)(parse_url($episodeAudioUrl, PHP_URL_HOST) ?? ''));
        $path = strtolower((string)(parse_url($episodeAudioUrl, PHP_URL_PATH) ?? ''));
        $candidates = [];

        if ($host === 'sphinx.acast.com' || str_ends_with($host, '.acast.com')) {
            if (preg_match('~/s/([^/]+)/~i', $path, $match) === 1) {
                $showId = trim((string)($match[1] ?? ''));
                if ($showId !== '') {
                    $candidates[] = 'https://feeds.acast.com/public/shows/' . rawurlencode($showId);
                }
            }
        }

        if ($host === 'audio.podigee-cdn.net' || str_ends_with($host, '.podigee-cdn.net')) {
            $slug = Str::slug(strtr($podcastTitle, [
                'Ä' => 'Ae',
                'Ö' => 'Oe',
                'Ü' => 'Ue',
                'ä' => 'ae',
                'ö' => 'oe',
                'ü' => 'ue',
                'ß' => 'ss',
            ]));
            if ($slug !== '') {
                $candidates[] = 'https://' . $slug . '.podigee.io/feed/mp3';
            }
        }

        foreach ($candidates as $candidate) {
            $validated = $this->validatePodcastFeedUrl($candidate);
            if ($validated !== null) {
                return $validated;
            }
        }

        return null;
    }

    private function inferPocketCastsFeedUrlFromKnownShow(string $podcastTitle, string $podcastWebsiteUrl = '', string $canonicalUrl = ''): ?string
    {
        $titleSlug = Str::slug(strtr($podcastTitle, [
            'Ä' => 'Ae',
            'Ö' => 'Oe',
            'Ü' => 'Ue',
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            'ß' => 'ss',
        ]));
        $websiteHost = strtolower((string)(parse_url((string)$this->safeUrl($podcastWebsiteUrl), PHP_URL_HOST) ?? ''));
        $canonicalPath = strtolower((string)(parse_url((string)$this->safeUrl($canonicalUrl), PHP_URL_PATH) ?? ''));
        $candidates = [];

        if ($titleSlug === 'das-podcast-ufo' || str_contains($canonicalPath, '/das-podcast-ufo/')) {
            $candidates[] = 'https://feeds.acast.com/public/shows/podcast-ufo';
        }

        if (
            $titleSlug === 'tischgespraeche-der-round-table-podcast' ||
            $websiteHost === 'round-table.de' ||
            $websiteHost === 'www.round-table.de'
        ) {
            $candidates[] = 'https://tischgespraeche-der-round-table-podcast.podigee.io/feed/mp3';
        }

        foreach ($candidates as $candidate) {
            $validated = $this->validatePodcastFeedUrl($candidate);
            if ($validated !== null) {
                return $validated;
            }
        }

        return null;
    }

    private function validatePodcastFeedUrl(?string $candidate): ?string
    {
        $url = $this->safeUrl((string)$candidate);
        if ($url === null || $this->looksLikePodcastFeedUrl($url) === false) {
            return null;
        }

        try {
            $response = Remote::get($url, [
                'timeout' => 10,
                'agent' => 'hnzio share embed cache',
                'headers' => [
                    'Accept' => 'application/rss+xml,application/xml,text/xml;q=0.9,*/*;q=0.1',
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        $status = (int)($response->code() ?? 0);
        if ($status < 200 || $status >= 400) {
            return null;
        }

        $headers = array_change_key_case((array)$response->headers(), CASE_LOWER);
        $contentType = strtolower(trim((string)($headers['content-type'] ?? '')));
        $body = ltrim((string)$response->content());

        if (
            str_contains($contentType, 'xml') ||
            str_contains($contentType, 'rss') ||
            str_starts_with($body, '<?xml') ||
            str_contains(substr($body, 0, 512), '<rss') ||
            str_contains(substr($body, 0, 512), '<feed')
        ) {
            return $url;
        }

        return null;
    }

    private function inferPodcastFeedFormat(string $mimeType = '', string $enclosureUrl = '', string $feedUrl = ''): string
    {
        $mimeType = strtolower(trim($mimeType));
        if (str_contains($mimeType, 'mpeg')) {
            return 'mp3';
        }
        if (str_contains($mimeType, 'aac') || str_contains($mimeType, 'mp4') || str_contains($mimeType, 'm4a')) {
            return 'aac';
        }
        if (str_contains($mimeType, 'opus')) {
            return 'opus';
        }
        if (str_contains($mimeType, 'ogg')) {
            return 'ogg';
        }

        foreach ([$enclosureUrl, $feedUrl] as $url) {
            $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?? ''));
            if ($path === '') {
                continue;
            }
            if (preg_match('~\.mp3$~', $path) === 1 || str_contains($path, '/mp3')) {
                return 'mp3';
            }
            if (preg_match('~\.(m4a|aac)$~', $path) === 1 || str_contains($path, '/aac') || str_contains($path, '/mp4')) {
                return 'aac';
            }
            if (preg_match('~\.opus$~', $path) === 1 || str_contains($path, '/opus')) {
                return 'opus';
            }
            if (preg_match('~\.(ogg|oga)$~', $path) === 1 || str_contains($path, '/ogg')) {
                return 'ogg';
            }
        }

        return 'mp3';
    }

    private function isEpisodeSpecificImageUrl(string $url, ?string $episodeId, string $episodeSlug): bool
    {
        $needleId = strtolower(trim((string)$episodeId));
        $needleSlug = strtolower(trim($episodeSlug));
        $haystack = strtolower(trim($url));
        if ($haystack === '') {
            return false;
        }

        if ($needleId !== '' && str_contains($haystack, $needleId)) {
            return true;
        }

        if ($needleSlug !== '') {
            if (str_contains($haystack, $needleSlug)) {
                return true;
            }
            if (preg_match('/([a-z]{1,6}\d{1,4})/', $needleSlug, $m) === 1) {
                $episodeToken = strtolower((string)($m[1] ?? ''));
                if ($episodeToken !== '' && str_contains($haystack, $episodeToken)) {
                    return true;
                }
            }
        }

        return str_contains($haystack, '/episode');
    }

    private function findPocketEpisodeNodeById(mixed $node, string $episodeId): ?array
    {
        if (!is_array($node)) {
            return null;
        }

        foreach (['uuid', 'id', 'episodeUuid', 'episode_uuid'] as $key) {
            if (isset($node[$key]) && is_string($node[$key]) && strcasecmp(trim($node[$key]), $episodeId) === 0) {
                return $node;
            }
        }

        foreach ($node as $value) {
            $nested = $this->findPocketEpisodeNodeById($value, $episodeId);
            if (is_array($nested)) {
                return $nested;
            }
        }

        return null;
    }

    private function findValueByKeys(mixed $node, array $keys, ?string $episodeId = null): ?string
    {
        if (!is_array($node)) {
            return null;
        }

        foreach ($keys as $key) {
            if (!array_key_exists($key, $node)) {
                continue;
            }

            $value = $node[$key];
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string)$value;
            }
            if (is_array($value)) {
                if (isset($value['url']) && is_string($value['url']) && trim($value['url']) !== '') {
                    return trim($value['url']);
                }
                foreach ($value as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        return trim($item);
                    }
                }
            }
        }

        foreach ($node as $value) {
            $nested = $this->findValueByKeys($value, $keys, $episodeId);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function findImageInJsonLd(mixed $node): ?string
    {
        if (is_string($node)) {
            return null;
        }

        if (!is_array($node)) {
            return null;
        }

        if (isset($node['image'])) {
            $img = $node['image'];
            if (is_string($img) && trim($img) !== '') {
                return trim($img);
            }
            if (is_array($img)) {
                if (isset($img['url']) && is_string($img['url']) && trim($img['url']) !== '') {
                    return trim($img['url']);
                }
                foreach ($img as $candidate) {
                    if (is_string($candidate) && trim($candidate) !== '') {
                        return trim($candidate);
                    }
                    $nested = $this->findImageInJsonLd($candidate);
                    if ($nested !== null) {
                        return $nested;
                    }
                }
            }
        }

        foreach ($node as $value) {
            $nested = $this->findImageInJsonLd($value);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function pocketCastsPartsFromUrl(string $url): array
    {
        if (!$this->isPocketCastsUrl($url)) {
            return [];
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($part) => $part !== ''));

        $podcastTitle = '';
        $episodeNumber = '';

        $podcastIndex = array_search('podcast', $segments, true);
        if ($podcastIndex !== false) {
            $podcastSlug = (string)($segments[$podcastIndex + 1] ?? '');
            $episodeSlug = (string)($segments[$podcastIndex + 3] ?? '');

            if ($podcastSlug !== '') {
                $podcastTitle = $this->titleFromSlug($podcastSlug);
            }

            if ($episodeSlug !== '') {
                $token = '';
                if (preg_match('/(?:^|[-_])([a-z]{1,6}\d{1,4}|\d{1,4}|s\d+e\d+)(?:$|[-_])/i', $episodeSlug, $m) === 1) {
                    $token = (string)($m[1] ?? '');
                }
                if ($token === '') {
                    $parts = preg_split('/[-_]+/', strtolower($episodeSlug));
                    if (is_array($parts) && $parts !== []) {
                        $token = (string)end($parts);
                    }
                }
                $episodeNumber = $this->normalizePocketCastsEpisodeNumber($token);
            }
        }

        return array_filter([
            'podcastTitle' => $podcastTitle,
            'episodeNumber' => $episodeNumber,
        ], static fn ($value) => is_string($value) && $value !== '');
    }

    private function pocketCastsPodcastPageUrl(string $url): ?string
    {
        if (!$this->isPocketCastsUrl($url)) {
            return null;
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($part) => $part !== ''));
        $podcastIndex = array_search('podcast', $segments, true);
        if ($podcastIndex === false) {
            return null;
        }

        $podcastSlug = (string)($segments[$podcastIndex + 1] ?? '');
        $podcastId = (string)($segments[$podcastIndex + 2] ?? '');
        if ($podcastSlug === '' || $podcastId === '') {
            return null;
        }

        return 'https://pocketcasts.com/podcast/' . rawurlencode($podcastSlug) . '/' . rawurlencode($podcastId);
    }

    private function pocketCastsEpisodeTitleFromUrl(string $url): string
    {
        if (!$this->isPocketCastsUrl($url)) {
            return '';
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($part) => $part !== ''));
        $podcastIndex = array_search('podcast', $segments, true);
        if ($podcastIndex === false) {
            return '';
        }

        $episodeSlug = (string)($segments[$podcastIndex + 3] ?? '');
        if ($episodeSlug === '') {
            return '';
        }

        $slug = trim(urldecode($episodeSlug));
        if ($slug === '') {
            return '';
        }

        $parts = explode('-', strtolower($slug));
        if (count($parts) > 1 && preg_match('/^[a-z]{1,4}\d{2,4}$/', (string)$parts[0]) === 1) {
            array_shift($parts);
            $slug = implode('-', $parts);
        }

        $slug = preg_replace('/\s+/', ' ', str_replace(['_', '-'], ' ', $slug));
        if (!is_string($slug)) {
            return '';
        }

        return trim(ucfirst($slug));
    }

    private function pocketCastsEpisodeNumberFromTitle(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (preg_match('/^\s*([a-z]{2,6}\s*\d{2,4}|s\d+\s*e\d+)\b/i', $text, $m) === 1) {
            return $this->normalizePocketCastsEpisodeNumber($m[1]);
        }

        if (preg_match('/\b(S\d+\s*E\d+|E\d{1,4}|EP\.?\s*\d{1,4}|EPISODE\s*\d{1,4}|FOLGE\s*\d{1,4}|#\d{1,4})\b/i', $text, $m) === 1) {
            return $this->normalizePocketCastsEpisodeNumber($m[1]);
        }

        return '';
    }

    private function pocketCastsEpisodeNumberFromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $patterns = [
            // Typical forms: E463, EP463, FS303, TAD171
            '/\b((?:e|ep|fs|tad|tng|voy|stt)\s*[-_.]?\s*\d{2,4})\b/i',
            // Typical forms around separators in Pocket Casts UI: "E463 · ..."
            '/>\s*([a-z]{1,6}\s*[-_.]?\s*\d{2,4})\s*[·|:\-–]/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m) === 1) {
                $candidate = trim((string)($m[1] ?? ''));
                $normalized = $this->normalizePocketCastsEpisodeNumber($candidate);
                if ($normalized !== '') {
                    return $normalized;
                }
            }
        }

        return '';
    }

    private function pocketCastsEpisodeImageFromHtml(string $url, string $html): ?string
    {
        if (!$this->isPocketCastsUrl($url) || trim($html) === '') {
            return null;
        }

        $slug = $this->pocketCastsEpisodeSlugFromUrl($url);
        $episodeId = strtolower((string)($this->pocketCastsEpisodeIdFromUrl($url) ?? ''));
        if ($slug === '' && $episodeId === '') {
            return null;
        }

        if (preg_match_all('/https?:\/\/[^\s"\'<>]+?\.(?:jpg|jpeg|png|webp)/i', $html, $matches) < 1) {
            return null;
        }

        $slugNeedle = strtolower($slug);
        foreach (($matches[0] ?? []) as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') {
                continue;
            }
            $candidateLower = strtolower($candidate);
            if (($slugNeedle !== '' && str_contains($candidateLower, $slugNeedle)) || ($episodeId !== '' && str_contains($candidateLower, $episodeId))) {
                return $candidate;
            }
        }

        return null;
    }

    private function pocketCastsEpisodeSlugFromUrl(string $url): string
    {
        if (!$this->isPocketCastsUrl($url)) {
            return '';
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($part) => $part !== ''));
        $podcastIndex = array_search('podcast', $segments, true);
        if ($podcastIndex === false) {
            return '';
        }

        return trim((string)($segments[$podcastIndex + 3] ?? ''));
    }

    private function pocketCastsEpisodeIdFromUrl(string $url): ?string
    {
        if (!$this->isPocketCastsUrl($url)) {
            return null;
        }

        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn ($part) => $part !== ''));
        $podcastIndex = array_search('podcast', $segments, true);
        if ($podcastIndex === false) {
            return null;
        }

        $episodeId = trim((string)($segments[$podcastIndex + 4] ?? ''));
        if ($episodeId === '') {
            return null;
        }

        return $episodeId;
    }

    private function titleFromSlug(string $slug): string
    {
        $slug = trim(urldecode($slug));
        if ($slug === '') {
            return '';
        }

        $slug = str_replace(['_', '-'], ' ', $slug);
        $slug = preg_replace('/\s+/', ' ', $slug);
        if (!is_string($slug)) {
            return '';
        }

        return trim(ucwords(strtolower($slug)));
    }

    private function normalizePocketCastsEpisodeNumber(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return '';
        }

        $raw = preg_replace('/\s+/', ' ', $raw) ?? $raw;

        if (preg_match('/^[a-z]*s?(\d{3,4})$/', $raw, $m) === 1) {
            $digits = $m[1];
            $season = substr($digits, 0, 1);
            return 'S' . $season . ' E' . $digits;
        }

        if (preg_match('/^s\s*(\d+)\s*e\s*(\d+)$/i', $raw, $m) === 1) {
            return 'S' . (int)$m[1] . ' E' . (int)$m[2];
        }

        if (preg_match('/^e\s*[-_.]?\s*(\d{1,4})$/i', $raw, $m) === 1) {
            return 'E' . (int)$m[1];
        }

        if (preg_match('/^(?:episode\s*|ep\.?\s*|folge\s*|#)\s*(\d{1,4})$/i', $raw, $m) === 1) {
            return 'E' . (int)$m[1];
        }

        if (preg_match('/^(?:e|ep\.?\s*|folge\s*|#)\s*(\d+)$/i', $raw, $m) === 1) {
            return 'E' . (int)$m[1];
        }

        if (preg_match('/^(?:fs|tad|tng|voy|stt|pod|ep)\s*\d{2,4}$/i', $raw) === 1) {
            return strtoupper($raw);
        }

        if (preg_match('/^\d{2,4}$/', $raw) === 1) {
            return 'E' . (int)$raw;
        }

        return '';
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    private function pluginOption(string $key, mixed $default = null): mixed
    {
        return $this->kirby->option('hnzio.share-embed.' . $key, $this->kirby->option('hnz.share-embed.' . $key, $default));
    }
}

class_alias(HnzioShareEmbedService::class, 'HnzShareEmbedService');

Kirby::plugin('hnzio/share-embed', [
    'options' => hnzioShareEmbedPluginOptions(),
        'tags' => [
            'share' => [
            'attr' => ['refresh', 'title', 'desc', 'image', 'author', 'profile', 'network', 'avatar', 'favicon'],
            'html' => function ($tag): string {
                $sourceUrl = trim((string)$tag->value());
                if (V::url($sourceUrl) !== true) {
                    return Html::a($sourceUrl, Html::encode($sourceUrl), ['rel' => 'noopener noreferrer']);
                }

                $service = new HnzioShareEmbedService(kirby());
                $data = $service->resolve($sourceUrl, [
                    'force' => $tag->attr('refresh'),
                    'title' => $tag->attr('title'),
                    'desc' => $tag->attr('desc'),
                    'image' => $tag->attr('image'),
                    'author' => $tag->attr('author'),
                    'profile' => $tag->attr('profile'),
                    'network' => $tag->attr('network'),
                    'avatar' => $tag->attr('avatar'),
                    'favicon' => $tag->attr('favicon'),
                ]);

                return $service->render($data);
            },
        ],
    ],
    'areas' => [
        'site' => [
            'buttons' => [
                'shareEmbedRefresh' => function ($page) {
                    if (!$page instanceof Kirby\Cms\Page) {
                        return null;
                    }

                    if (in_array($page->intendedTemplate()->name(), ['bookmarks', 'audiofeed', 'watchlog'], true) === false) {
                        return [
                            'disabled' => true,
                            'style' => 'display:none',
                        ];
                    }

                    $sourceUrl = null;
                    foreach (['watchUrl', 'audioUrl', 'bookmarkUrl'] as $fieldName) {
                        $candidate = trim((string)$page->content()->get($fieldName)->value());
                        if (V::url($candidate) === true) {
                            $sourceUrl = $candidate;
                            break;
                        }
                    }

                    if ($sourceUrl === null) {
                        return [
                            'disabled' => true,
                            'style' => 'display:none',
                        ];
                    }

                    return [
                        'icon' => 'refresh',
                        'text' => 'Embed',
                        'theme' => 'notice',
                        'dialog' => 'shareEmbedRefresh/' . $page->uuid()->toString(),
                    ];
                },
            ],
            'dialogs' => [
                'shareEmbedRefresh/(:any)' => [
                    'load' => function (string $id) {
                        $page = page('page://' . $id);
                        if (!$page) {
                            return true;
                        }

                        $sourceUrl = null;
                        foreach (['watchUrl', 'audioUrl', 'bookmarkUrl'] as $fieldName) {
                            $candidate = trim((string)$page->content()->get($fieldName)->value());
                            if (V::url($candidate) === true) {
                                $sourceUrl = $candidate;
                                break;
                            }
                        }

                        if ($sourceUrl === null) {
                            return [
                                'component' => 'k-text-dialog',
                                'props' => [
                                    'size' => 'small',
                                    'text' => 'Keine gueltige Share-URL gefunden.',
                                    'submitButton' => false,
                                ],
                            ];
                        }

                        return [
                            'component' => 'k-text-dialog',
                            'props' => [
                                'size' => 'small',
                                'text' => "Embed-Cache jetzt neu aufbauen?\n\nURL: " . $sourceUrl,
                                'submitButton' => [
                                    'icon' => 'refresh',
                                    'text' => 'Jetzt aktualisieren',
                                    'theme' => 'notice',
                                ],
                            ],
                        ];
                    },
                    'submit' => function (string $id) {
                        $page = page('page://' . $id);
                        if (!$page || class_exists(HnzioShareEmbedService::class) === false) {
                            return true;
                        }

                        $sourceUrl = null;
                        foreach (['watchUrl', 'audioUrl', 'bookmarkUrl'] as $fieldName) {
                            $candidate = trim((string)$page->content()->get($fieldName)->value());
                            if (V::url($candidate) === true) {
                                $sourceUrl = $candidate;
                                break;
                            }
                        }

                        if ($sourceUrl === null) {
                            return true;
                        }

                        $service = new HnzioShareEmbedService(kirby());
                        $resolved = $service->resolve($sourceUrl, [
                            'force' => true,
                        ]);

                        if ($page->intendedTemplate()->name() === 'watchlog') {
                            $watchType = strtolower(trim((string)$page->content()->get('watchType')->value()));
                            $isMovieOrSeries = in_array($watchType, ['movie', 'series'], true);
                            if ($isMovieOrSeries === false) {
                                $remoteTitle = trim((string)($resolved['title'] ?? ''));
                                $remoteDuration = trim((string)($resolved['videoDuration'] ?? $resolved['audioDuration'] ?? ''));

                                $normalizedTitle = strtolower($remoteTitle);
                                $badTitle = in_array($normalizedTitle, [
                                    '',
                                    'youtube',
                                    'youtube-video',
                                    'youtube video',
                                ], true);

                                $updates = [];
                                if ($badTitle === false) {
                                    $updates['title'] = $remoteTitle;
                                    // SEO-Panel Feld aus tobimori/seo
                                    $updates['metaTitle'] = $remoteTitle;
                                }
                                if ($remoteDuration !== '') {
                                    $updates['watchRuntime'] = $remoteDuration;
                                }

                                $writeFields = (bool)hnzioShareEmbedOption('refresh-write-fields', false);
                                if ($writeFields && !empty($updates)) {
                                    try {
                                        kirby()->impersonate('kirby', function () use ($page, $updates) {
                                            $page->update($updates);
                                        });
                                    } catch (Throwable $e) {
                                    }
                                }
                            }
                        }

                        return true;
                    },
                ],
            ],
        ],
    ],
]);
