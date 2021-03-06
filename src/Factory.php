<?php
namespace PharIo\Phive;

use PharIo\GnuPG\Factory as GnuPGFactory;
use PharIo\GnuPG\GnuPG;
use PharIo\Phive\Cli;
use PharIo\Version\VersionConstraintParser;

class Factory {

    /**
     * @var CurlConfig
     */
    private $curlConfig;

    /**
     * @var PhiveVersion
     */
    private $version;

    /**
     * @var Cli\Request
     */
    private $request;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var PharRegistry
     */
    private $registry;

    /**
     * @param Cli\Request  $request
     * @param PhiveVersion $version
     */
    public function __construct(Cli\Request $request, PhiveVersion $version = null) {
        $this->request = $request;
        $this->version = $version;
    }

    /**
     * @return Cli\Runner
     */
    public function getRunner() {
        return new Cli\Runner(
            $this->getCommandLocator(),
            $this->getOutput(),
            $this->getPhiveVersion(),
            $this->getEnvironment(),
            $this->request
        );
    }

    /**
     * @return VersionCommand
     */
    public function getVersionCommand() {
        return new VersionCommand;
    }

    /**
     * @return HelpCommand
     */
    public function getHelpCommand() {
        return new HelpCommand(
            $this->getEnvironment(),
            $this->getOutput()
        );
    }

    /**
     * @return SkelCommand
     */
    public function getSkelCommand() {
        return new SkelCommand(
            new SkelCommandConfig($this->request->parse(new SkelContext()), getcwd()),
            $this->getPhiveVersion()
        );
    }

    /**
     * @return UpdateRepositoryListCommand
     */
    public function getUpdateRepositoryListCommand() {
        return new UpdateRepositoryListCommand($this->getRemoteSourcesListFileLoader());
    }

    /**
     * @return RemoveCommand
     */
    public function getRemoveCommand() {
        return new RemoveCommand(
            new RemoveCommandConfig($this->request->parse(new RemoveContext()), $this->getTargetDirectoryLocator()),
            $this->getPharRegistry(),
            $this->getOutput(),
            $this->getPhiveXmlConfig($this->request->getOptions()->hasOption('global'))
        );
    }

    /**
     * @return ResetCommand
     */
    public function getResetCommand() {
        return new ResetCommand(
            new ResetCommandConfig($this->request->parse(new ResetContext())),
            $this->getPharRegistry(),
            $this->getEnvironment(),
            $this->getPharInstaller()
        );
    }

    /**
     * @return InstallCommand
     */
    public function getInstallCommand() {
        return new InstallCommand(
            new InstallCommandConfig(
                $this->request->parse(new InstallContext()),
                $this->getPhiveXmlConfig($this->request->getOptions()->hasOption('global')),
                $this->getEnvironment(),
                $this->getTargetDirectoryLocator()
            ),
            $this->getInstallService(),
            $this->getRequestedPharResolverBuilder()->build($this->getLocalFirstResolvingStrategy()),
            $this->getReleaseSelector()
        );
    }

    /**
     * @return UpdateCommand
     */
    public function getUpdateCommand() {
        $config = new UpdateCommandConfig(
            $this->request->parse(new UpdateContext()),
            $this->getPhiveXmlConfig($this->request->getOptions()->hasOption('global')),
            $this->getTargetDirectoryLocator()
        );

        $resolvingStrategy = $config->preferOffline() ? $this->getLocalFirstResolvingStrategy() : $this->getRemoteFirstResolvingStrategy();

        return new UpdateCommand(
            $config,
            $this->getInstallService(),
            $this->getRequestedPharResolverBuilder()->build($resolvingStrategy),
            $this->getPhiveXmlConfig($this->request->getOptions()->hasOption('global')),
            $this->getReleaseSelector()
        );
    }

    /**
     * @return ListCommand
     */
    public function getListCommand() {
        return new ListCommand(
            $this->getSourcesList(),
            $this->getLocalSourcesList(),
            $this->getOutput()
        );
    }

    /**
     * @return PurgeCommand
     */
    public function getPurgeCommand() {
        return new PurgeCommand(
            $this->getPharRegistry(),
            $this->getOutput()
        );
    }

    /**
     * @return ComposerCommand
     */
    public function getComposerCommand() {
        return new ComposerCommand(
            new ComposerCommandConfig(
                $this->request->parse(new ComposerContext()),
                $this->getPhiveXmlConfig($this->request->getOptions()->hasOption('global')),
                $this->getEnvironment(),
                $this->getTargetDirectoryLocator(),
                $this->getEnvironment()->getWorkingDirectory()
            ),
            $this->getComposerService(),
            $this->getInstallService(),
            $this->getConsoleInput(),
            $this->getRequestedPharResolverBuilder()->build(
                $this->getLocalFirstResolvingStrategy()
            ),
            $this->getReleaseSelector()
        );
    }

    /**
     * @return StatusCommand
     */
    public function getStatusCommand() {
        return new StatusCommand(
            $this->getPhiveXmlConfig($this->request->getOptions()->hasOption('global')),
            $this->getPharRegistry(),
            $this->getOutput()
        );
    }

    /**
     * @return SelfupdateCommand
     */
    public function getSelfupdateCommand() {
        return new SelfupdateCommand(
            $this->getPharDownloader(),
            $this->getGithubAliasResolver(),
            $this->getEnvironment(),
            $this->getPhiveVersion(),
            $this->getOutput(),
            $this->getReleaseSelector()
        );
    }

    /**
     * @return DefaultCommand
     */
    public function getDefaultCommand() {
        return new DefaultCommand(
            $this->getVersionCommand(),
            $this->getHelpCommand(),
            new DefaultCommandConfig($this->request->getOptions())
        );
    }

    /**
     * @return TargetDirectoryLocator
     */
    private function getTargetDirectoryLocator() {
        return new TargetDirectoryLocator(
            $this->getConfig(),
            $this->getPhiveXmlConfig($this->request->getOptions()->hasOption('global')),
            $this->request->getOptions()
        );
    }

    /**
     * @return CommandLocator
     */
    private function getCommandLocator() {
        return new CommandLocator($this);
    }

    /**
     * @return Cli\Output
     */
    public function getOutput() {
        return (new Cli\OutputLocator(new Cli\OutputFactory()))->getOutput(
            $this->getEnvironment(),
            !$this->request->getOptions()->hasOption('no-progress')
        );
    }

    /**
     * @return PhiveVersion
     */
    private function getPhiveVersion() {
        if (!$this->version) {
            $this->version = new GitAwarePhiveVersion($this->getGit());
        }

        return $this->version;
    }

    /**
     * @return InstallService
     */
    private function getInstallService() {
        return new InstallService(
            $this->getPhiveXmlConfig($this->request->getOptions()->hasOption('global')),
            $this->getPharInstaller(),
            $this->getPharRegistry(),
            $this->getPharService(),
            $this->getCompatibilityService()
        );
    }

    /**
     * @return Git
     */
    private function getGit() {
        return new Git($this->getEnvironment()->getWorkingDirectory());
    }

    /**
     * @return Environment
     */
    private function getEnvironment() {
        if (null === $this->environment) {
            $locator = new EnvironmentLocator();
            $this->environment = $locator->getEnvironment(PHP_OS);
        }

        return $this->environment;
    }

    /**
     * @return RemoteSourcesListFileLoader
     */
    public function getRemoteSourcesListFileLoader() {
        return new RemoteSourcesListFileLoader(
            $this->getConfig()->getSourcesListUrl(),
            $this->getConfig()->getHomeDirectory()->file('repositories.xml'),
            $this->getFileDownloader(),
            $this->getOutput(),
            $this->getConfig()->getMaxAgeForSourcesList()
        );
    }

    /**
     * @return LocalSourcesListFileLoader
     */
    public function getLocalSourcesListFileLoader() {
        return new LocalSourcesListFileLoader(
            $this->getConfig()->getHomeDirectory()->file('local.xml')
        );
    }

    /**
     * @return Config
     */
    public function getConfig() {
        return new Config(
            $this->getEnvironment(),
            $this->request->getOptions()
        );
    }

    /**
     * @return FileDownloader
     */
    public function getFileDownloader() {
        return new FileDownloader(
            $this->getRetryingHttpClient(),
            $this->getFileStorageCacheBackend()
        );
    }

    /**
     * @return RetryingHttpClient
     */
    private function getRetryingHttpClient() {
        return new RetryingHttpClient(
            $this->getOutput(),
            $this->getHttpClient(),
            5
        );
    }

    /**
     * @return CurlHttpClient
     */
    public function getHttpClient() {
        return new CurlHttpClient(
            $this->getCurlConfig(),
            $this->getHttpProgressRenderer(),
            new Curl()
        );
    }

    /**
     * @return HttpProgressRenderer
     */
    private function getHttpProgressRenderer() {
        return new HttpProgressRenderer($this->getOutput());
    }

    /**
     * @return PharRegistry
     */
    public function getPharRegistry() {
        if ($this->registry === null) {
            $this->registry = new PharRegistry(
                new XmlFile(
                    $this->getConfig()->getHomeDirectory()->file('/phars.xml'),
                    'https://phar.io/phive/installdb',
                    'phars'
                ),
                $this->getConfig()->getHomeDirectory()->child('phars')
            );
        }

        return $this->registry;
    }

    /**
     * @return PharService
     */
    private function getPharService() {
        return new PharService(
            $this->getPharRegistry(),
            $this->getPharDownloader()
        );
    }

    /**
     * @return PharDownloader
     */
    private function getPharDownloader() {
        return new PharDownloader(
            $this->getRetryingHttpClient(),
            $this->getGnupgSignatureVerifier(),
            $this->getChecksumService(),
            $this->getPharRegistry()
        );
    }

    /**
     * @return SignatureVerifier
     */
    private function getGnupgSignatureVerifier() {
        return new GnupgSignatureVerifier($this->getGnupg(), $this->getKeyService());
    }

    /**
     * @return \Gnupg|GnuPG
     */
    private function getGnupg() {
        $home = $this->getConfig()->getHomeDirectory()->child('gpg');
        $bin = $this->getConfig()->getGPGBinaryPath();

        return (new GnuPGFactory($bin))->createGnuPG($home);
    }

    /**
     * @return KeyService
     */
    private function getKeyService() {
        return new KeyService(
            $this->getPgpKeyDownloader(),
            $this->getGnupgKeyImporter(),
            $this->getConfig()->getTrustedKeyIds(),
            $this->getOutput(),
            $this->getConsoleInput()
        );
    }

    /**
     * @return GnupgKeyDownloader
     */
    private function getPgpKeyDownloader() {
        return new GnupgKeyDownloader(
            $this->getRetryingHttpClient(),
            include __DIR__ . '/../conf/pgp-keyservers.php',
            $this->getOutput()
        );
    }

    /**
     * @return KeyImporter
     */
    private function getGnupgKeyImporter() {
        return new GnupgKeyImporter($this->getGnupg());
    }

    /**
     * @return Cli\ConsoleInput
     */
    private function getConsoleInput() {
        return new Cli\ConsoleInput($this->getOutput());
    }

    /**
     * @return ChecksumService
     */
    private function getChecksumService() {
        return new ChecksumService();
    }

    /**
     * @return PharInstaller
     */
    private function getPharInstaller() {
        return $this->getPharInstallerLocator()->getPharInstaller($this->environment);
    }

    /**
     * @param bool $global
     *
     * @return PhiveXmlConfig
     */
    private function getPhiveXmlConfig($global) {
        if ($global) {
            return new GlobalPhiveXmlConfig(
                new XmlFile(
                    $this->getPhiveXmlConfigFileLocator()->getFile($global),
                    'https://phar.io/phive',
                    'phive'
                ),
                new VersionConstraintParser()
            );
        }
        return new LocalPhiveXmlConfig(
            new XmlFile(
                $this->getPhiveXmlConfigFileLocator()->getFile($global),
                'https://phar.io/phive',
                'phive'
            ),
            new VersionConstraintParser()
        );
    }

    /**
     * @return PhiveXmlConfigFileLocator
     */
    private function getPhiveXmlConfigFileLocator() {
        return new PhiveXmlConfigFileLocator(
            $this->getEnvironment(),
            $this->getConfig()
        );
    }

    /**
     * @return ComposerService
     */
    private function getComposerService() {
        return new ComposerService($this->getSourcesList());
    }

    /**
     * @return RequestedPharResolverService
     */
    public function getRequestedPharResolverService() {
        return new RequestedPharResolverService();
    }

    /**
     * @return GithubAliasResolver
     */
    public function getGithubAliasResolver() {
        return new GithubAliasResolver(
            $this->getHttpClient(),
            $this->getFileDownloader(),
            $this->getOutput()
        );
    }

    /**
     * @return PharInstallerLocator
     */
    private function getPharInstallerLocator() {
        return new PharInstallerLocator(new PharInstallerFactory($this));
    }

    /**
     * @return FileStorageCacheBackend
     */
    private function getFileStorageCacheBackend() {
        return new FileStorageCacheBackend($this->getConfig()->getHomeDirectory()->child('http-cache'));
    }

    /**
     * @return RequestedPharResolverServiceBuilder
     */
    private function getRequestedPharResolverBuilder() {
        return new RequestedPharResolverServiceBuilder($this);
    }

    /**
     * @return SourcesList
     */
    private function getSourcesList() {
        return $this->getRemoteSourcesListFileLoader()->load();
    }

    /**
     * @return SourcesList
     */
    private function getLocalSourcesList() {
        return $this->getLocalSourcesListFileLoader()->load();
    }

    /**
     * @return LocalFirstResolvingStrategy
     */
    private function getLocalFirstResolvingStrategy() {
        return new LocalFirstResolvingStrategy($this->getRequestedPharResolverFactory());
    }

    /**
     * @return RemoteFirstResolvingStrategy
     */
    private function getRemoteFirstResolvingStrategy() {
        return new RemoteFirstResolvingStrategy($this->getRequestedPharResolverFactory());
    }

    /**
     * @return RequestedPharResolverFactory
     */
    private function getRequestedPharResolverFactory() {
        return new RequestedPharResolverFactory($this);
    }

    private function getCompatibilityService() {
        return new CompatibilityService(
            $this->getOutput(),
            $this->getConsoleInput()
        );
    }

    private function getCurlConfig() {
        if ($this->curlConfig === null) {
            $this->curlConfig = (new CurlConfigBuilder($this->getEnvironment(), $this->getPhiveVersion()))->build();
        }

        return $this->curlConfig;
    }

    private function getReleaseSelector() {
        return new ReleaseSelector($this->getOutput());
    }
}
