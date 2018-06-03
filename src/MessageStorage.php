<?php

namespace Printful\GettextCms;

use Gettext\Languages\Language;
use Gettext\Translation;
use Gettext\Translations;
use Printful\GettextCms\Exceptions\InvalidTranslationException;
use Printful\GettextCms\Interfaces\MessageRepositoryInterface;
use Printful\GettextCms\Structures\MessageItem;

/**
 * Class handles all saving and retrieving logic of translations using the given repository
 */
class MessageStorage
{
    /** @var MessageRepositoryInterface */
    private $repository;

    /**
     * Cache for plural form counts
     * @var array [locale => plural count, ..]
     */
    private $pluralFormCache = [];

    public function __construct(MessageRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Save translation object for a single domain and locale
     *
     * @param Translations $translations
     * @throws InvalidTranslationException
     */
    public function saveTranslations(Translations $translations)
    {
        $locale = $translations->getLanguage();
        $domain = (string)$translations->getDomain();

        if (!$locale) {
            throw new InvalidTranslationException('Locale is missing');
        }

        if (!$domain) {
            throw new InvalidTranslationException('Domain is missing');
        }

        foreach ($translations as $v) {
            $this->saveSingleTranslation($locale, $domain, $v);
        }
    }

    /**
     * Save a translation to database by merging it to a previously saved version.
     *
     * @param string $locale
     * @param string $domain
     * @param Translation $translation
     */
    public function saveSingleTranslation(string $locale, string $domain, Translation $translation)
    {
        // Make a clone so we don't modify the passed instance
        $translation = $translation->getClone();

        $key = $this->getKey($locale, $domain, $translation);

        $existingItem = $this->repository->getSingle($key);

        if ($existingItem->exists()) {
            $existingTranslation = $this->itemToTranslation($existingItem);
            $translation->mergeWith($existingTranslation);
            $item = $this->translationToItem($locale, $domain, $translation);

            // Override the disabled state of the existing translation
            $item->isDisabled = $translation->isDisabled();
        } else {
            $item = $this->translationToItem($locale, $domain, $translation);
        }

        $item->hasOriginalTranslation = $translation->hasTranslation();
        $item->requiresTranslating = $this->requiresTranslating($locale, $translation);

        $this->repository->save($item);
    }

    /**
     * Check if some translations are missing (original or missing plural forms)
     *
     * @param string $locale
     * @param Translation $translation
     * @return bool
     */
    private function requiresTranslating(string $locale, Translation $translation): bool
    {
        if (!$translation->hasTranslation()) {
            return true;
        }

        if ($translation->hasPlural()) {
            $translatedPluralCount = count(array_filter($translation->getPluralTranslations()));
            // If there are less plural translations than language requires, this needs translating
            return $this->getPluralCount($locale) !== $translatedPluralCount;
        }

        return false;
    }

    /**
     * Get number of plural forms for this locale
     *
     * @param string $locale
     * @return int
     * @throws \InvalidArgumentException Thrown in the locale is not correct
     */
    private function getPluralCount(string $locale): int
    {
        if (!array_key_exists($locale, $this->pluralFormCache)) {
            $info = Language::getById($locale);
            $this->pluralFormCache[$locale] = count($info->categories);
        }

        return $this->pluralFormCache[$locale];
    }

    /**
     * Generate a unique key for storage (basically a primary key)
     *
     * @param string $locale
     * @param string $domain
     * @param Translation $translation
     * @return string
     */
    private function getKey(string $locale, string $domain, Translation $translation): string
    {
        return md5($locale . '|' . $domain . '|' . $translation->getContext() . '|' . $translation->getOriginal());
    }

    /**
     * Convert a message item to a translation item
     *
     * @param MessageItem $item
     * @return Translation
     */
    private function itemToTranslation(MessageItem $item): Translation
    {
        $translation = new Translation($item->context, $item->original, $item->originalPlural);

        $translation->setTranslation($item->translation);
        $translation->setPluralTranslations($item->pluralTranslations);
        $translation->setDisabled($item->isDisabled);

        foreach ($item->references as $v) {
            $translation->addReference($v[0], $v[1]);
        }

        foreach ($item->comments as $v) {
            $translation->addComment($v);
        }

        foreach ($item->extractedComments as $v) {
            $translation->addExtractedComment($v);
        }

        return $translation;
    }

    /**
     * Convert a translation item to a message item
     *
     * @param string $locale
     * @param string $domain
     * @param Translation $translation
     * @return MessageItem
     */
    private function translationToItem(string $locale, string $domain, Translation $translation): MessageItem
    {
        $item = new MessageItem;

        $item->key = $this->getKey($locale, $domain, $translation);
        $item->domain = $domain;
        $item->locale = $locale;
        $item->context = (string)$translation->getContext();
        $item->original = $translation->getOriginal();
        $item->translation = $translation->getTranslation();
        $item->originalPlural = $translation->getPlural();
        $item->pluralTranslations = $translation->getPluralTranslations();
        $item->references = $translation->getReferences();
        $item->comments = $translation->getComments();
        $item->extractedComments = $translation->getExtractedComments();
        $item->isDisabled = $translation->isDisabled();

        return $item;
    }

    /**
     * All translations, including disabled, enabled and untranslated
     *
     * @param string $locale
     * @param $domain
     * @return Translations
     */
    public function getAll(string $locale, $domain): Translations
    {
        return $this->convertItems(
            $locale,
            (string)$domain,
            $this->repository->getAll($locale, (string)$domain)
        );
    }

    /**
     * All enabled translations including untranslated
     *
     * @param string $locale
     * @param $domain
     * @return Translations
     */
    public function getAllEnabled(string $locale, $domain): Translations
    {
        return $this->convertItems(
            $locale,
            (string)$domain,
            $this->repository->getEnabled($locale, (string)$domain)
        );
    }

    /**
     * Enabled and translated translations only
     *
     * @param string $locale
     * @param $domain
     * @return Translations
     */
    public function getEnabledTranslated(string $locale, $domain): Translations
    {
        $items = $this->repository->getEnabledTranslated($locale, (string)$domain);

        return $this->convertItems($locale, (string)$domain, $items);
    }

    /**
     * Enabled and translated translations only
     *
     * @param string $locale
     * @param $domain
     * @return Translations
     */
    public function getRequiresTranslating(string $locale, $domain): Translations
    {
        return $this->convertItems(
            $locale,
            (string)$domain,
            $this->repository->getRequiresTranslating($locale, (string)$domain)
        );
    }

    /**
     * Converts message items to a translation object
     *
     * @param string $locale
     * @param string|null $domain
     * @param MessageItem[] $items
     * @return Translations
     */
    private function convertItems(string $locale, $domain, array $items): Translations
    {
        $domain = (string)$domain;

        $translations = new Translations;
        $translations->setDomain($domain);
        $translations->setLanguage($locale);

        foreach ($items as $v) {
            $translation = $this->itemToTranslation($v);
            $translations[] = $translation;
        }

        return $translations;
    }

    /**
     * Mark all messages in the given domain and locale as disabled
     *
     * @param string $locale
     * @param string $domain
     */
    public function disableAll(string $locale, string $domain)
    {
        $this->repository->disableAll($locale, $domain);
    }
}