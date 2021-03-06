<?php

use Behat\Behat\Context\Context;
use Behat\Behat\Tester\Exception\PendingException;

/**
 * Defines application features from the specific context.
 */
class FeatureContext implements Context
{
    protected $buildDir;
    protected $serverDir;
    protected $updateServerDir;
    protected $tmpDownloadDir;
    protected $downloadURL = 'https://download.nextcloud.com/server/releases/';
    protected $dailyDownloadURL = 'https://download.nextcloud.com/server/daily/latest-';
    protected $prereleasesDownloadURL = 'https://download.nextcloud.com/server/prereleases/';
    /** @var resource */
    protected $updaterServerProcess = null;
    /** @var string[] */
    protected $CLIOutput;
    /** @var integer */
    protected $CLIReturnCode;
    /** @var string */
    protected $autoupdater = '1';

    public function __construct()
    {
        $baseDir = __DIR__ . '/../../data/';
        $this->serverDir = $baseDir . 'server/';
        $this->tmpDownloadDir = $baseDir . 'downloads/';
        $this->updateServerDir = $baseDir . 'update-server/';
        $this->buildDir = $baseDir . '../../';
        if(!file_exists($baseDir) && !mkdir($baseDir)) {
            throw new RuntimeException('Creating tmp download dir failed');
        }
        if(!file_exists($this->serverDir) && !mkdir($this->serverDir)) {
            throw new RuntimeException('Creating server dir failed');
        }
        if(!file_exists($this->tmpDownloadDir) && !mkdir($this->tmpDownloadDir)) {
            throw new RuntimeException('Creating tmp download dir failed');
        }
        if(!file_exists($this->updateServerDir) && !mkdir($this->updateServerDir)) {
            throw new RuntimeException('Creating update server dir failed');
        }
    }

    /**
     * @AfterScenario
     */
    public function stopUpdateServer()
    {
        if(is_resource($this->updaterServerProcess)) {
            proc_terminate($this->updaterServerProcess);
            proc_close($this->updaterServerProcess);
        }
    }

    /**
     * @Given /the current (installed )?version is ([0-9.]+((beta|RC)[0-9]?)?|stable[0-9]+|master)/
     */
    public function theCurrentInstalledVersionIs($installed, $version)
    {
        // recursive deletion of server folder
        if(file_exists($this->serverDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->serverDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $fileInfo) {
                $action = $fileInfo->isDir() ? 'rmdir' : 'unlink';
                $action($fileInfo->getRealPath());
            }
            $state = rmdir($this->serverDir);
            if($state === false) {
                throw new \Exception('Could not rmdir ' . $this->serverDir);
            }
        }

        $filename = 'nextcloud-' . $version . '.zip';

        if (!file_exists($this->tmpDownloadDir . $filename)) {
            $fp = fopen($this->tmpDownloadDir . $filename, 'w+');
            $url = $this->downloadURL . $filename;
            if (strpos($version, 'RC') !== false || strpos($version, 'beta') !== false) {
                $url = $this->prereleasesDownloadURL . 'nextcloud-' . $version . '.zip';
            } else if(strpos($version, 'stable') !== false || strpos($version, 'master') !== false) {
                $url = $this->dailyDownloadURL . $version . '.zip';
            }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_FILE, $fp);
            if(curl_exec($ch) === false) {
                throw new \Exception('Curl error: ' . curl_error($ch));
            }
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if($httpCode !== 200) {
                throw new \Exception('Download failed - HTTP code: ' . $httpCode);
            }
            curl_close($ch);
            fclose($fp);
        }

        $zip = new ZipArchive;
        $zipState = $zip->open($this->tmpDownloadDir . $filename);
        if ($zipState === true) {
            $zip->extractTo($this->serverDir);
            $zip->close();
        } else {
            throw new \Exception('Cant handle ZIP file. Error code is: '.$zipState);
        }

        if($installed === '') {
			// the instance should not be installed
			return;
		}

        chdir($this->serverDir . 'nextcloud');
        shell_exec('chmod +x occ');
        exec('./occ maintenance:install --admin-user=admin --admin-pass=admin 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception('Install failed' . PHP_EOL . join(PHP_EOL, $output));
        }
    }

    /**
     * @Given there is no update available
     */
    public function thereIsNoUpdateAvailable()
    {
		$this->runUpdateServer();

		$content = '';
		file_put_contents($this->updateServerDir . 'index.php', $content);
	}

	/**
	 * @Given  the autoupdater is disabled
	 */
	public function theAutoupdaterIsDisabled() {
		$this->autoupdater = '0';
	}

	/**
	 * @When the CLI updater is run successfully
	 */
	public function theCliUpdaterIsRunSuccessfully()
	{
		$this->theCliUpdaterIsRun();

		if ($this->CLIReturnCode !== 0) {
			throw new Exception('updater failed' . PHP_EOL . join(PHP_EOL, $this->CLIOutput));
		}
	}

    /**
     * @When the CLI updater is run
     */
    public function theCliUpdaterIsRun()
    {
        if(!file_exists($this->buildDir . 'updater.phar')) {
            throw new Exception('updater.phar not available - please build it in advance via "box build -c box.json"');
        }
        copy($this->buildDir . 'updater.phar', $this->serverDir . 'nextcloud/updater/updater');
        chdir($this->serverDir . 'nextcloud/updater');
        chmod($this->serverDir . 'nextcloud/updater/updater', 0755);
        exec('./updater -n', $output, $returnCode);

		$this->CLIOutput = $output;
		$this->CLIReturnCode = $returnCode;
    }

    /**
     * @Given /there is an update to version ([0-9.]+) available/
     */
    public function thereIsAnUpdateToVersionAvailable($version)
    {
		$this->runUpdateServer();

        $content = '<?php
        header("Content-Type: application/xml");
        ?>
<?xml version="1.0" encoding="UTF-8"?>
<nextcloud>
 <version>' . str_replace(['9.1', '9.2'], ['10.0', '11.0'], $version) . '</version>
 <versionstring>Nextcloud ' . $version . '</versionstring>
 <url>https://download.nextcloud.com/server/releases/nextcloud-' . $version . '.zip</url>
 <web>https://docs.nextcloud.org/server/10/admin_manual/maintenance/manual_upgrade.html</web>
 <autoupdater>' . $this->autoupdater . '</autoupdater>
</nextcloud>
';
        file_put_contents($this->updateServerDir . 'index.php', $content);

    }

	/**
	 * @Given /there is an update to prerelease version of (.*) available/
	 */
	public function thereIsAnUpdateToPrereleaseVersionAvailable($version)
	{
		$this->runUpdateServer();

		$content = '<?php
        header("Content-Type: application/xml");
        ?>
<?xml version="1.0" encoding="UTF-8"?>
<nextcloud>
 <version>' . str_replace(['9.1', '9.2'], ['10.0', '11.0'], $version) . '</version>
 <versionstring>Nextcloud ' . $version . '</versionstring>
 <url>https://download.nextcloud.com/server/prereleases/nextcloud-' . $version . '.zip</url>
 <web>https://docs.nextcloud.org/server/10/admin_manual/maintenance/manual_upgrade.html</web>
 <autoupdater>1</autoupdater>
</nextcloud>
';
		file_put_contents($this->updateServerDir . 'index.php', $content);

	}

	/**
	 * @Given /there is an update to daily version of (.*) available/
	 */
	public function thereIsAnUpdateToDailyVersionAvailable($version)
	{
		$this->runUpdateServer();

		$content = '<?php
        header("Content-Type: application/xml");
        ?>
<?xml version="1.0" encoding="UTF-8"?>
<nextcloud>
 <version>100.0.0.0</version>
 <versionstring>Nextcloud ' . $version . '</versionstring>
 <url>https://download.nextcloud.com/server/daily/latest-' . $version . '.zip</url>
 <web>https://docs.nextcloud.org/server/10/admin_manual/maintenance/manual_upgrade.html</web>
 <autoupdater>1</autoupdater>
</nextcloud>
';
		file_put_contents($this->updateServerDir . 'index.php', $content);

	}

	/**
	 * runs the updater server
	 * @throws Exception
	 */
    protected function runUpdateServer()
	{
		$configFile = $this->serverDir . 'nextcloud/config/config.php';
		$content = file_get_contents($configFile);
		$content = preg_replace('!\$CONFIG\s*=\s*array\s*\(!', "\$CONFIG = array(\n 'updater.server.url' => 'http://localhost:8870/',", $content );
		file_put_contents($configFile, $content);

		if (!is_null($this->updaterServerProcess)) {
			throw new Exception('Update server already started');
		}

		$cmd = "php -S localhost:8870 -t " . $this->updateServerDir . " 2>/dev/null 1>/dev/null";
		$this->updaterServerProcess = proc_open($cmd, [], $pipes, $this->updateServerDir);

		if(!is_resource($this->updaterServerProcess)) {
			throw new Exception('Update server could not be started');
		}

		// to let the server start
		sleep(1);
	}

    /**
     * @Then /the installed version should be ([0-9.]+)/
     */
	public function theInstalledVersionShouldBe2($version)
    {
        /** @var $OC_Version */
        require $this->serverDir . 'nextcloud/version.php';

        $installedVersion = join('.', $OC_Version);
        // Hack for version number mapping
        $installedVersion = str_replace(['9.1', '9.2'], ['10.0', '11.0'], $installedVersion);

        if (strpos($installedVersion, $version) !== 0) {
            throw new Exception('Version mismatch - Installed: ' . $installedVersion . ' Wanted: ' . $version);
        }
    }

	/**
	 * @Then /maintenance mode should be (on|off)/
	 */
	public function maintenanceModeShouldBe($state)
	{

		chdir($this->serverDir . 'nextcloud');
		shell_exec('chmod +x occ');
		exec('./occ maintenance:mode', $output, $returnCode);

		$expectedOutput = 'Maintenance mode is currently ' .
			($state === 'on' ? 'enabled' : 'disabled');

		if ($returnCode !== 0 || strpos(join(PHP_EOL, $output), $expectedOutput) === false) {
			throw new Exception('Maintenance mode does not match ' . PHP_EOL . join(PHP_EOL, $output));
		}
	}

	/**
	 * @Then /upgrade is (not required|required)/
	 */
	public function upgradeIs($state)
	{

		chdir($this->serverDir . 'nextcloud');
		shell_exec('chmod +x occ');
		exec('./occ status', $output, $returnCode);

		$upgradeOutput = 'Nextcloud or one of the apps require upgrade';

		$outputString = join(PHP_EOL, $output);
		if ($returnCode !== 0) {
			throw new Exception('Return code of status output does not match ' . PHP_EOL . $outputString);
		}

		if ($state === 'not required') {
			if (strpos($outputString, $upgradeOutput) !== false) {
				throw new Exception('Upgrade is required ' . PHP_EOL . join(PHP_EOL, $output));
			}
		} else {
			if (strpos($outputString, $upgradeOutput) === false) {
				throw new Exception('Upgrade is not required ' . PHP_EOL . join(PHP_EOL, $output));
			}
		}
	}

	/**
	 * @Then /the return code should not be (\S*)/
	 */
	public function theReturnCodeShouldNotBe($expectedReturnCode)
	{
		if ($this->CLIReturnCode === (int)$expectedReturnCode) {
			throw new Exception('Return code does match but should not match: ' . $this->CLIReturnCode . PHP_EOL . join(PHP_EOL, $this->CLIOutput));
		}
	}

	/**
	 * @Then /the output should contain "(.*)"/
	 */
	public function theOutputShouldBe($expectedOutput)
	{
		if (strpos(join(PHP_EOL, $this->CLIOutput), $expectedOutput) === false) {
			throw new Exception('Output does not match: ' . PHP_EOL . join(PHP_EOL, $this->CLIOutput));
		}
	}

	/**
	 * @Given /the version number is decreased in the config.php to enforce upgrade/
	 */
	public function theVersionNumberIsDecreasedInTheConfigPHPToEnforceUpgrade()
	{
		$configFile = $this->serverDir . 'nextcloud/config/config.php';
		$content = file_get_contents($configFile);
		$content = preg_replace("!'version'\s*=>\s*'(\d+\.\d+\.\d+)\.\d+!", "'version' => '$1", $content);
		file_put_contents($configFile, $content);
	}
}
