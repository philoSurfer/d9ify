<?php

namespace D9ify\Commands;

use Composer\IO\IOInterface;
use D9ify\Exceptions\D9ifyExceptionBase;
use D9ify\Site\Directory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ProcessCommand
 *
 *
 *
 * @package D9ify
 */
class ProcessCommand extends Command
{

    /**
     * @var string
     */
    public static $HELP_TEXT = [
        "*******************************************************************************",
        "* THIS SCRIPT IS IN ALPHA VERSION STATUS AND AT THIS POINT HAS VERY LITTLE    *",
        "* ERROR CHECKING. PLEASE USE AT YOUR OWN RISK.                                *",
        "* The guide to use this file is in /README.md                                 *",
        "*******************************************************************************",
    ];

    /**
     * @var \Composer\IO\IOInterface|null
     */
    protected ?IOInterface $composerIOInterface = null;

    /**
     * @var string
     */
    protected static $defaultName = 'd9ify';
    /**
     * @var \D9ify\Site\Directory
     */
    protected Directory $sourceDirectory;
    /**
     * @var \D9ify\Site\Directory
     */
    protected Directory $destinationDirectory;

    /**
     * Configure.
     */
    protected function configure()
    {
        $this
            ->setName('d9ify:process')
            ->setDescription('The magic d9ificiation machine')
            ->addArgument('source', InputArgument::REQUIRED, 'The pantheon site name or ID of the site')
            ->setHelp(static::$HELP_TEXT)
            ->setDefinition(new InputDefinition([
                new InputArgument(
                    'source',
                    InputArgument::REQUIRED,
                    "Pantheon Site Name or Site ID of the source"
                ),
                new InputArgument(
                    'destination',
                    InputArgument::OPTIONAL,
                    "Pantheon Site Name or Site ID of the destination"
                ),
            ]));
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|void
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $output->writeln(static::$HELP_TEXT);
            $this->setSourceDirectory(
                Directory::factory(
                    $input->getArgument('source'),
                    $output
                )
            );
            $org = $this->getSourceDirectory()->getInfo()->getOrganization();
            $this->setDestinationDirectory(
                Directory::factory(
                    $input->getArgument('destination') ??
                    $this->sourceDirectory->getSiteInfo()->getName() . "-" . date('Y'),
                    $output,
                    $org
                )
            );
            $this->copyRepositoriesFromSource($input, $output);
            $this->updateDestModulesAndThemesFromSource($input, $output);
            $this->updateDestEsLibrariesFromSource($input, $output);
            $this->writeComposer($input, $output);
            $this->destinationComposerInstall();
            /**
             * @Step
             * @description
             * ### Copy Custom Code.
             *
             * This step looks for {MODULENAME}.info.yml files that also have "custom"
             * in the path. If they have THEME in the path it copies them to web/themes/custom.
             * If they have "module" in the path, it copies the folder to web/modules/custom.
             *
             */
            $this->copyCustomCode($input, $output);
            /**
             * @Step
             * @description
             * ### Ensure pantheon.yaml has preferred values.
             */
            $this->copyConfigFiles($input, $output);
            /**
             * @Setp
             * @description
             * ### Download Database backup.
             */



            // TODO:
            // 1. commit-push code/config/composer additions.
            // 1. Rsync remote files to local directory
            // 1. Rsync remote files back up to new site
            // 1. Download database backup
            // 1. Restore Database backup to new site
        } catch (D9ifyExceptionBase $d9ifyException) {
            // TODO: Composer install exception help text
            $output->writeln((string) $d9ifyException);
            exit(1);
        } catch (\Exception $e) {
            // TODO: General help text and how to restart the process
            $output->writeln("Script ended in Exception state. " . $e->getMessage());
            $output->writeln($e->getTraceAsString());
            exit(1);
        } catch (\Throwable $t) {
            // TODO: General help text and how to restart the process
            $output->write("Script ended in error state. " . $t->getMessage());
            $output->writeln($t->getTraceAsString());
            exit(1);
        }
        exit(0);
    }

    /**
     * @Step
     * @description
     * ### Set Source and Destination.
     *
     * Source Param is not optional and needs to be
     * a pantheon site ID or name.
     *
     * @param \D9ify\Site\Directory $sourceDirectory
     */
    public function setSourceDirectory(Directory $sourceDirectory): void
    {
        $this->sourceDirectory = $sourceDirectory;
    }

    /**
     * @return \D9ify\Site\Directory
     */
    public function getSourceDirectory(): Directory
    {
        return $this->sourceDirectory;
    }

    /**
     * @Step
     * @description
     * ### Set Destination directory
     *
     * Destination name will be {source}-{THIS YEAR} by default
     * if you don't provide a value. Destination name will be
     * {source}-{THIS YEAR} by default if you don't provide a value.
     *
     *
     * @param \D9ify\Site\Directory $destinationDirectory
     */
    public function setDestinationDirectory(Directory $destinationDirectory): void
    {
        $this->destinationDirectory = $destinationDirectory;
    }

    /**
     * @return \D9ify\Site\Directory
     */
    public function getDestinationDirectory(): Directory
    {
        return $this->destinationDirectory;
    }


    /**
     * @Step
     * @description
     * ### Clone Source & Destination.
     *
     * Clone both sites to folders inside this root directory.
     * If destination does not exist, create the using Pantheon's
     * Terminus API. If destination doesn't exist, Create it.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function copyRepositoriesFromSource(InputInterface $input, OutputInterface $output)
    {
        $this->getSourceDirectory()->ensure(false);
        $this->getDestinationDirectory()->ensure(true);
        $this->destinationDirectory->getComposerObject()->setRepositories(
            $this->sourceDirectory->getComposerObject()->getOriginal()['repositories'] ?? []
        );
        //$output->writeln(print_r($this->destinationDirectory->getComposerObject()->__toArray(), true));
    }

    /**
     * @Step
     * @description
     * ### Move over Contrib
     *
     * Spelunk the old site for MODULE.info.yaml and after reading
     * those files. This step searches for every {modulename}.info.yml. If that
     * file has a 'project' proerty (i.e. it's been thru the automated services at
     * drupal.org), it records that property and version number and ensures
     * those values are in the composer.json 'require' array. Your old composer
     * file will re renamed backup-*-composer.json.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     */
    protected function updateDestModulesAndThemesFromSource(InputInterface $input, OutputInterface $output)
    {
        /**
         * https://regex101.com/r/60GonN/1
         * Get every .info.y{a}ml file in source.
         */
        $infoFiles = $this->sourceDirectory->spelunkFilesFromRegex('/(\.info\.yml|\.info\.yaml?)/', $output);
        $toMerge = [];
        $composerFile = $this->getDestinationDirectory()
            ->getComposerObject();
        foreach ($infoFiles as $fileName => $fileInfo) {
            $contents = yaml_parse_file($fileName);
            $project = $contents['project'];
            $version = $contents['version'];
            if (!empty($project) && !empty($version)) {
                $composerFile->addRequirement(
                    "drupal/" . $project,
                    "^" . str_replace("8.x-", "", $version)
                );
            }
        }
        $output->write(PHP_EOL);
        $output->write(PHP_EOL);
        $output->writeln([
            "*******************************************************************************",
            "* Found new Modules & themes from the source site:                            *",
            "*******************************************************************************",
            print_r($composerFile->getDiff(), true)
        ]);
        return 0;
    }


    /**
     * @Step
     * @description
     * ### JS contrib/drupal libraries
     *
     * Process /libraries folder if exists & Add ES Libraries to the composer
     * install payload
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @throws \JsonException
     */
    protected function updateDestEsLibrariesFromSource(InputInterface $input, OutputInterface $output)
    {
        /**
         * @extra
         * https://regex101.com/r/EHYzcz/1
         * Get every package.json in the libraries folder.
         */
        $fileList = $this->sourceDirectory->spelunkFilesFromRegex('/libraries\/[0-9a-z-]*\/(package\.json$)/', $output);
        $repos = $this->sourceDirectory->getComposerObject()->getOriginal()['repositories'];
        $composerFile = $this->getDestinationDirectory()->getComposerObject();
        foreach ($fileList as $key => $file) {
            $package = \json_decode(file_get_contents($file->getRealPath()), true, 10, JSON_THROW_ON_ERROR);
            $repoString = (string) $package['name'];
            if (empty($repoString)) {
                $repoString = is_string($package['repository']) ?
                    $package['repository'] : $package['repository']['url'];
            }
            if (empty($repoString) || is_array($repoString)) {
                $output->writeln([
                    "*******************************************************************************",
                    "* Skipping the file below because the package.json file does not have         *",
                    "* a 'name' or 'repository' property. Add it by hand to the composer file.     *",
                    "* like so: \"npm-asset/{npm-registry-name}\": \"{Version Spec}\" in           *",
                    "* the REQUIRE section. Search for the id on https://www.npmjs.com             *",
                    "*******************************************************************************",
                    $file->getRealPath(),
                ]);
                continue;
            }
            $array = explode("/", $repoString);
            $libraryName = @array_pop($array);
            if (isset($repos[$libraryName])) {
                $composerFile->addRequirement(
                    $repos[$libraryName]['package']['name'],
                    $repos[$libraryName]['package']['version']
                );
                continue;
            }
            if ($libraryName !== "") {
                // Last ditch guess:
                $composerFile->addRequirement("npm-asset/" . $libraryName, "^" . $package['version']);
            }
        }
        $installPaths = $composerFile->getExtraProperty('installer-paths');
        if (!isset($installPaths['web/libraries/{$name}'])) {
            $installPaths['web/libraries/{$name}'] = [];
        }
        $installPaths['web/libraries/{$name}'] = array_unique(
            array_merge($installPaths['web/libraries/{$name}'] ?? [], [
                "type:bower-asset",
                "type:npm-asset",
            ])
        );

        $composerFile->setExtraProperty('installer-paths', $installPaths);
        $installerTypes = $composerFile->getExtraProperty('installer-types') ?? [];
        $composerFile->setExtraProperty(
            'installer-types',
            array_unique(
                array_merge($installerTypes, [
                    "bower-asset",
                    "npm-asset",
                    "library",
                ])
            )
        );
        $output->write(PHP_EOL);
        $output->write(PHP_EOL);
        $output->writeln([
            "*******************************************************************************",
            "* Found new ESLibraries from the source site:                                 *",
            "*******************************************************************************",
            print_r($composerFile->getDiff(), true)
            ]);
    }

    /**
     * @Step
     * @description
     * ### Write the composer file.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int|mixed
     */
    protected function writeComposer(InputInterface $input, OutputInterface $output)
    {
        $output->writeln([
            "*********************************************************************",
            "* These changes are being applied to the destination site composer: *",
            "*********************************************************************",
        ]);
        $output->writeln(print_r($this->destinationDirectory
                                     ->getComposerObject()
                                     ->getDiff(), true));
        $output->writeln(
            sprintf(
                "Write these changes to the composer file at %s?",
                $this->destinationDirectory
                    ->getComposerObject()
                    ->getRealPath()
            )
        );
        $this->destinationDirectory
            ->getComposerObject()
            ->backupFile();
        $question = new ConfirmationQuestion(" Type '(y)es' to continue: ", false);
        $helper = $this->getHelper('question');
        if ($helper->ask($input, $output, $question)) {
            return $this->getDestinationDirectory()
                ->getComposerObject()
                ->write();
        }
        $output->writeln("The composer Files were not changed");
        return 0;
    }

    /**
     * @Step
     * @description
     * ### composer install
     *
     * Exception will be thrown if install fails.
     *
     */
    public function destinationComposerInstall()
    {
        $this->getDestinationDirectory()->install($output);
    }

    /**
     * @return IOInterface|null
     */
    public function getComposerIOInterface(): ?IOInterface
    {
        return $this->composerIOInterface;
    }

    /**
     * @param IOInterface $composerIOInterface
     */
    public function setComposerIOInterface(IOInterface $composerIOInterface): void
    {
        $this->composerIOInterface = $composerIOInterface;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return bool
     */
    public function copyCustomCode(InputInterface $input, OutputInterface $output) :bool
    {
        $failure_list = [];
        /**
         * @extra
         * REGEX: https://regex101.com/r/kUWCou/1
         * get every .info file with "custom" in the path, e.g.
         * ✓  web/modules/custom/milken_migrate/milken_migrate.info.yaml
         * ✗  web/modules/contrib/entity_embed/entity_embed.info.yaml
         * ✓  web/modules/custom/milken_base/milken_base.info.yaml
         */
        $infoFiles = $this
            ->sourceDirectory
            ->spelunkFilesFromRegex('/custom\/[0-9a-z-_]*\/[0-9a-z-_]*(\.info\.yml|\.info\.yaml?)/', $output);
        $this->getDestinationDirectory()->ensureCustomCodeFoldersExist($input, $output);

        foreach ($infoFiles as $fileName => $fileInfo) {
            try {
                $contents = Yaml::parse(file_get_contents($fileName));
            } catch (\Exception $exception) {
                if ($output->isVerbose()) {
                    $output->writeln($exception->getTraceAsString());
                }
                continue;
            }

            // Skip any info file that has the "project" setting because it will be in contrib.
            // Skip any info file that doesn't have a "type" setting.
            if (!isset($contents['type'])) {
                continue;
            }
            $sourceDir = dirname($fileInfo->getRealPath());
            switch ($contents['type']) {
                case "module":
                    $destination = $this->getDestinationDirectory()->getClonePath() . "/web/modules/custom";
                    break;

                case "theme":
                    $destination = $this->getDestinationDirectory()->getClonePath() . "/web/themes/custom";
                    break;

                default:
                    continue 2;
            }

            $command = sprintf(
                "cp -Rf %s %s",
                $sourceDir,
                $destination
            );
            if ($output->isVerbose()) {
                $output->writeln($command);
            }

            exec(
                $command,
                $result,
                $status
            );
            if ($status !== 0) {
                $failure_list[$fileName] = $result;
            }
            if (!isset($contents['core'])) {
                $contents['core'] = "^9";
            }
            // If the module does not have d9 in the "core" module value, add it
            if (isset($contents['core']) && strpos($contents['core'], "9") === false) {
                $contents['core'] .= "| ^9";
                file_put_contents($fileName, Yaml::dump($contents, 1, 5));
            }
        }
        $output->write(PHP_EOL);
        $output->write(PHP_EOL);
        $failures = count($failure_list);
        $output->writeln(sprintf("Copy operations are complete with %d errors.", $failures));
        if ($failures) {
            $toWrite = [
                    "*******************************************************************************",
                    "* The following files had an error while copying. You will need to inspect    *",
                    "* The folders by hand or diff them. I'm not saying the folders weren't copied.*",
                    "* I'm saying I'm not sure they were copied in-tact. Double check the contents *",
                    "* They might have errored on a single file which would stop the copy.         *",
                    "* If you want to see the complete output from the copies, run this command    *",
                    "* with the --verbose switch.                                                  *",
                    "*******************************************************************************",
                ] + array_keys($failure_list);
            $output->writeln($toWrite);
            if ($output->isVerbose()) {
                $output->write(print_r($failure_list, true));
            }
        }
        return true;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function copyConfigFiles(InputInterface $input, OutputInterface $output)
    {
        /**
         * https://regex101.com/r/vWIStG/1
         * Try to find the config directory.
         */
        $configDirectory = $this->getSourceDirectory()
            ->spelunkFilesFromRegex('/[!^core]\/(system\.site\.yml$)/', $output);
        print_r($configDirectory);
        exit();
    }
}
