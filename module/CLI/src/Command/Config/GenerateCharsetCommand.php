<?php
declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\Config;

use Shlinkio\Shlink\Core\Service\UrlShortener;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zend\I18n\Translator\TranslatorInterface;

class GenerateCharsetCommand extends Command
{
    /**
     * @var TranslatorInterface
     */
    private $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
        parent::__construct();
    }

    public function configure()
    {
        $this->setName('config:generate-charset')
             ->setDescription(sprintf($this->translator->translate(
                 'Generates a character set sample just by shuffling the default one, "%s". '
                 . 'Then it can be set in the SHORTCODE_CHARS environment variable'
             ), UrlShortener::DEFAULT_CHARS));
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $charSet = str_shuffle(UrlShortener::DEFAULT_CHARS);
        $output->writeln($this->translator->translate('Character set:') . sprintf(' <info>%s</info>', $charSet));
    }
}
