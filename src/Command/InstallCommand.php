<?php

/**
 * @file
 * Contains \Drupal\Console\Command\SecretCommand.
 */

namespace Pantheon\Quicksilver\Command;

use Robo\TaskCollection\Collection as RoboTaskCollection;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Dumper;

class InstallCommand extends Command
{
    use \Robo\Task\FileSystem\loadTasks;
    use \Robo\Task\File\loadTasks;
    use \Robo\Task\Vcs\loadTasks;

    protected function configure()
    {
        $this
            ->setName('install')
            ->addArgument(
                'project',
                InputArgument::REQUIRED,
                'Quicksilver example project to install'
            )
            ->setDescription("Install a Pantheon example for Quicksilver");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output = new SymfonyStyle($input, $output);

        $application = $this->getApplication();
        // $collection = new RoboTaskCollection();

        $repositoryLocations = $application->getConfig()->get('repositories');

        $home = getenv('HOME');
        $cwd = getcwd();

        $qsHome = "$home/.quicksilver";
        $qsExamples = "$qsHome/examples";
        $qsScripts = "private/scripts";
        $qsYml = "pantheon.yml";

        // If the examples do not exist, clone them
        $output->writeln('Fetch Quicksilver examples...');
        @mkdir($qsHome);
        @mkdir($qsExamples);
        foreach ($repositoryLocations as $name => $repo) {
            $output->writeln("Check repo $name => $repo:");
            $qsExampleDir = "$qsExamples/$name";
            if (!is_dir($qsExampleDir)) {
                $this->taskGitStack()
                    ->cloneRepo("https://github.com/pantheon-systems/quicksilver-examples.git", $qsExampleDir)
                    ->run();
            }
            else {
                chdir($qsExampleDir);
                $this->taskGitStack()
                    ->pull()
                    ->run();
                chdir($cwd);
            }
        }
        $examplePantheonYml = dirname(dirname(__DIR__)) . "/templates/example.pantheon.yml";

        // Create a "started" pantheon.yml if it does not already exist.
        if (!is_file($qsYml)) {
            $this->taskWriteToFile($qsYml)
                ->textFromFile($examplePantheonYml)
                ->run();
        }

        @mkdir(dirname($qsScripts));
        @mkdir($qsScripts);

        // Copy the requested command into the current site
        $requestedProject = $input->getArgument('project');
        $availableProjects = Finder::create()->directories()->in($qsExamples);
        $candidates = [];
        foreach ($availableProjects as $project) {
            if (strpos($project, $requestedProject) !== FALSE) {
                $candidates[] = $project;
            }
        }

        // Exit if there are no matches.
        if (empty($candidates)) {
            $output->writeln("Could not find project $requestedProject.");
            return;
        }
/*
        // If there are multipe potential matches, ask which one to install.
        if (count($candidates) > 1) {

        }
*/
        // Copy the project to the installation location
        $projectToInstall = (string) array_pop($candidates);
        $installLocation = "$qsScripts/" . basename($projectToInstall);
        $output->writeln("Copy $projectToInstall to $installLocation.");
        $this->taskCopyDir([$projectToInstall => $installLocation])->run();

        // Read the README file, if there is one
        $readme = dirname($projectToInstall) . '/README.md';
        if (file_exists($readme)) {
            $readmeContents = file_get_contents($readme);
            // Look for embedded quicksilver.yml examples in the README
            preg_match_all('/```yaml([^`]*)```/', $readmeContents, $matches, PREG_PATTERN_ORDER);
            $pantheonYmlExample = static::findExamplePantheonYml($matches[1]);
        }

        // If the README does not have an example, make one up
        if (empty($pantheonYmlExample)) {
            $pantheonYmlExample =
            [
                'workflows' =>
                [
                    'deploy' =>
                    [
                        'before' =>
                        [
                            [
                                'type' => 'webphp',
                                'description' => 'Describe task here.',
                            ],
                        ]
                    ],
                ]
            ];
        }

        // Load the pantheon.yml file
        $pantheonYml = Yaml::parse($qsYml);
        $changed = false;

        $availableProjects = Finder::create()->files()->name("*.php")->in($installLocation);
        foreach ($availableProjects as $script) {
            foreach ($pantheonYmlExample['workflows'] as $workflowName => $workflowData) {
                foreach ($workflowData as $phaseName => $phaseData) {
                    foreach ($phaseData as $taskData) {
                        $taskData['script'] = (string) $script;
                        if (!static::hasScript($pantheonYml, $workflowName, $phaseName, (string) $script)) {
                            $pantheonYml['workflows'][$workflowName][$phaseName][] = $taskData;
                            $changed = true;
                        }
                    }
                }
            }
        }

        // Write out the pantheon.yml file again.
        if ($changed) {
            $output->writeln("Update pantheon.yml.");

            $pantheonYmlText = Yaml::dump($pantheonYml, PHP_INT_MAX, 2);
            $this->taskWriteToFile($qsYml)
                ->text($pantheonYmlText)
                ->run();
        }
    }

    static protected function findExamplePantheonYml($listOfYml)
    {
        foreach ($listOfYml as $candidate) {
            $examplePantheonYml = Yaml::parse($candidate);
            if (array_key_exists('api_version', $examplePantheonYml)) {
                return $examplePantheonYml;
            }
        }
        return [];
    }

    static protected function hasScript($pantheonYml, $workflowName, $phaseName, $script) {
        if (isset($pantheonYml['workflows'][$workflowName][$phaseName])) {
            foreach ($pantheonYml['workflows'][$workflowName][$phaseName] as $taskInfo) {
                if ($taskInfo['script'] == $script) {
                    return true;
                }
            }
        }
        return false;
    }
}
