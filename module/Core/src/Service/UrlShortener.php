<?php
declare(strict_types=1);

namespace Shlinkio\Shlink\Core\Service;

use Cocur\Slugify\Slugify;
use Cocur\Slugify\SlugifyInterface;
use Doctrine\Common\Cache\Cache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\UriInterface;
use Shlinkio\Shlink\Common\Exception\RuntimeException;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Exception\EntityDoesNotExistException;
use Shlinkio\Shlink\Core\Exception\InvalidShortCodeException;
use Shlinkio\Shlink\Core\Exception\InvalidUrlException;
use Shlinkio\Shlink\Core\Exception\NonUniqueSlugException;
use Shlinkio\Shlink\Core\Repository\ShortUrlRepository;
use Shlinkio\Shlink\Core\Util\TagManagerTrait;

class UrlShortener implements UrlShortenerInterface
{
    use TagManagerTrait;

    const DEFAULT_CHARS = '123456789bcdfghjkmnpqrstvwxyzBCDFGHJKLMNPQRSTVWXYZ';

    /**
     * @var ClientInterface
     */
    private $httpClient;
    /**
     * @var EntityManagerInterface
     */
    private $em;
    /**
     * @var string
     */
    private $chars;
    /**
     * @var Cache
     */
    private $cache;
    /**
     * @var SlugifyInterface
     */
    private $slugger;
    /**
     * @var bool
     */
    private $urlValidationEnabled;

    public function __construct(
        ClientInterface $httpClient,
        EntityManagerInterface $em,
        Cache $cache,
        $urlValidationEnabled,
        $chars = self::DEFAULT_CHARS,
        SlugifyInterface $slugger = null
    ) {
        $this->httpClient = $httpClient;
        $this->em = $em;
        $this->cache = $cache;
        $this->urlValidationEnabled = $urlValidationEnabled;
        $this->chars = empty($chars) ? self::DEFAULT_CHARS : $chars;
        $this->slugger = $slugger ?: new Slugify();
    }

    /**
     * Creates and persists a unique shortcode generated for provided url
     *
     * @param UriInterface $url
     * @param string[] $tags
     * @param \DateTime|null $validSince
     * @param \DateTime|null $validUntil
     * @param string|null $customSlug
     * @param int|null $maxVisits
     * @return string
     * @throws NonUniqueSlugException
     * @throws InvalidUrlException
     * @throws RuntimeException
     */
    public function urlToShortCode(
        UriInterface $url,
        array $tags = [],
        \DateTime $validSince = null,
        \DateTime $validUntil = null,
        string $customSlug = null,
        int $maxVisits = null
    ): string {
        // If the url already exists in the database, just return its short code
        $shortUrl = $this->em->getRepository(ShortUrl::class)->findOneBy([
            'originalUrl' => $url,
        ]);
        if ($shortUrl !== null) {
            return $shortUrl->getShortCode();
        }

        // Check if the validation of url is enabled in the config
        if (true === $this->urlValidationEnabled) {
            // Check that the URL exists
            $this->checkUrlExists($url);
        }
        $customSlug = $this->processCustomSlug($customSlug);

        // Transactionally insert the short url, then generate the short code and finally update the short code
        try {
            $this->em->beginTransaction();

            // First, create the short URL with an empty short code
            $shortUrl = new ShortUrl();
            $shortUrl->setOriginalUrl((string) $url)
                     ->setValidSince($validSince)
                     ->setValidUntil($validUntil)
                     ->setMaxVisits($maxVisits);
            $this->em->persist($shortUrl);
            $this->em->flush();

            // Generate the short code and persist it
            $shortCode = $customSlug ?? $this->convertAutoincrementIdToShortCode($shortUrl->getId());
            $shortUrl->setShortCode($shortCode)
                     ->setTags($this->tagNamesToEntities($this->em, $tags));
            $this->em->flush();

            $this->em->commit();
            return $shortCode;
        } catch (ORMException $e) {
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
                $this->em->close();
            }

            throw new RuntimeException('An error occurred while persisting the short URL', -1, $e);
        }
    }

    /**
     * Tries to perform a GET request to provided url, returning true on success and false on failure
     *
     * @param UriInterface $url
     * @return void
     */
    private function checkUrlExists(UriInterface $url)
    {
        try {
            $this->httpClient->request('GET', $url, ['allow_redirects' => [
                'max' => 15,
            ]]);
        } catch (GuzzleException $e) {
            throw InvalidUrlException::fromUrl($url, $e);
        }
    }

    /**
     * Generates the unique shortcode for an autoincrement ID
     *
     * @param int $id
     * @return string
     */
    private function convertAutoincrementIdToShortCode($id): string
    {
        $id = ((int) $id) + 200000; // Increment the Id so that the generated shortcode is not too short
        $length = strlen($this->chars);
        $code = '';

        while ($id > 0) {
            // Determine the value of the next higher character in the short code and prepend it
            $code = $this->chars[(int) fmod($id, $length)] . $code;
            $id = floor($id / $length);
        }

        return $this->chars[(int) $id] . $code;
    }

    private function processCustomSlug($customSlug)
    {
        if ($customSlug === null) {
            return null;
        }

        // If a custom slug was provided, check it is unique
        $customSlug = $this->slugger->slugify($customSlug);
        $shortUrl = $this->em->getRepository(ShortUrl::class)->findOneBy(['shortCode' => $customSlug]);
        if ($shortUrl !== null) {
            throw NonUniqueSlugException::fromSlug($customSlug);
        }

        return $customSlug;
    }

    /**
     * Tries to find the mapped URL for provided short code. Returns null if not found
     *
     * @param string $shortCode
     * @return string
     * @throws InvalidShortCodeException
     * @throws EntityDoesNotExistException
     */
    public function shortCodeToUrl(string $shortCode): string
    {
        $cacheKey = sprintf('%s_longUrl', $shortCode);
        // Check if the short code => URL map is already cached
        if ($this->cache->contains($cacheKey)) {
            return $this->cache->fetch($cacheKey);
        }

        // Validate short code format
        if (! preg_match('|[' . $this->chars . ']+|', $shortCode)) {
            throw InvalidShortCodeException::fromCharset($shortCode, $this->chars);
        }

        /** @var ShortUrlRepository $shortUrlRepo */
        $shortUrlRepo = $this->em->getRepository(ShortUrl::class);
        $shortUrl = $shortUrlRepo->findOneByShortCode($shortCode);
        if ($shortUrl === null) {
            throw EntityDoesNotExistException::createFromEntityAndConditions(ShortUrl::class, [
                'shortCode' => $shortCode,
            ]);
        }

        // Cache the shortcode
        $url = $shortUrl->getOriginalUrl();
        $this->cache->save($cacheKey, $url);
        return $url;
    }
}
