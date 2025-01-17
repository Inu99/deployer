<?php
namespace axenox\Deployer\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Tasks\ResultMessageStream;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use axenox\Deployer\DataTypes\BuildRecipeDataType;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Actions\iCreateData;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\CommonLogic\Filemanager;
use axenox\Deployer\DataConnectors\DeployerSshConnector;
use exface\Core\Factories\DataConnectionFactory;
use axenox\Deployer\Actions\Traits\BuildProjectTrait;
use exface\Core\DataTypes\StringDataType;
use Symfony\Component\Process\Process;

/**
 * Creates a build from the passed data.
 *
 * @author Andrej Kabachnik
 *
 */
class DeployBuild extends AbstractActionDeferred implements iCanBeCalledFromCLI, iCreateData
{
    use Traits\BuildProjectTrait;
    
    private $projectData = null;
    
    private $timeout = 600;
    
    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\AbstractAction::init()
     */
    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setInputRowsMax(1);
        $this->setInputObjectAlias('axenox.Deployer.deployment');
    }
    
    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        // $buildData based on object axenox.Deployer.deployment
        try {
            $deployData = $this->getInputDataSheet($task);
        } catch (ActionInputMissingError $e) {
            $deployData = DataSheetFactory::createFromObject($this->getInputObjectExpected());
            $deployData->addRow([
                'build' => $this->getBuildData($task, 'name'),
                'host' => $this->getHostData($task, 'name')
            ]);
        }
           
        $result = new ResultMessageStream($task);
        
        $generator = function () use ($task, $deployData, $result, $transaction) {
            
            $seconds = time();
            
            // Create deploy entry and mark it as "in progress"
            $deployData->setCellValue('status', 0, 50);
            $deployData->setCellValue('host', 0, $this->getHostData($task, 'UID'));
            $deployData->setCellValue('build', 0, $this->getBuildData($task, 'UID'));
            $deployData->setCellValue('started_on', 0, $seconds);
            $deployData->dataCreate(false, $transaction);
            
            $buildName = $this->getBuildData($task, 'name');
            $hostName = $this->getHostData($task, 'name');
            
            // run the deployer task via CLI
            if (getcwd() !== $this->getWorkbench()->filemanager()->getPathToBaseFolder()) {
                chdir($this->getWorkbench()->filemanager()->getPathToBaseFolder());
            }
            
            //create directories
            $projectFolder = $this->getProjectFolderRelativePath($task);
            $hostAliasFolderPath = $this->prepareDeployerProjectFolder($task);
            
            //build the command used for the actual deployment
            $deployTask = $this->prepareDeployerTask($task, $hostAliasFolderPath, $deployData); // testbuild\deploy.php LocalBldSshSelfExtractor --build=1.0.1...tar.gz
            $cmd .= 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . "dep {$deployTask}";

            $log = '';

            //execute deploy command
            $process = Process::fromShellCommandline($cmd, null, null, null, $this->getTimeout());
            $process->start();
            foreach ($process as $msg) {
                // Live output
                yield $msg;
                // Save to log
                $log .= $msg;
            }
            
            if ($process->isSuccessful() === false) {
                $deployData->setCellValue('status', 0, 90); // failed
                $msg = 'Deployment of ' . $buildName . ' on ' . $hostName . ' failed.';
            } else {
                $deployData->setCellValue('status', 0, 99); // completed
                $seconds = time() - $seconds;
                $msg = 'Deployment of ' . $buildName . ' on ' . $hostName . ' completed in ' . $seconds . ' seconds.';
            }
            yield $msg;
            $log .= $msg;
            
            $deployData->setCellValue('completed_on', 0, time());
            $deployData->setCellValue('log', 0, $log); 

            // Update deployment entry's state and save log to data source
            $deployData->dataUpdate(false, $transaction);
            
            //$this->cleanupFiles($projectFolder, $hostAliasFolderPath);

            // IMPORTANT: Trigger regular action post-processing as required by AbstractActionDeferred.
            $this->performAfterDeferred($result, $transaction);
        };
        
        $result->setMessageStreamGenerator($generator);
        return $result;
    }
     
    /**
     * function for getting a value out of the projects data
     *
     * @param TaskInterface $task
     * @param string $projectAttributeAlias
     * @throws ActionInputMissingError
     * @return string
     */
    protected function getProjectData(TaskInterface $task, string $projectAttributeAlias): string
    {
        if ($this->projectData === null) {
            $projectUid = $this->getHostData($task, 'project');
            
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.project');
            $ds->getColumns()->addMultiple([
                'alias',
                'build_recipe',
                'build_recipe_custom_path',
                'default_composer_json',
                'default_composer_auth_json',
                'default_config',
                'deployment_recipe',
                'deployment_receipe_custom_path',
                'name',
                'project_group'
            ]);
            $ds->addFilterFromString('UID', $projectUid, ComparatorDataType::EQUALS);
            $ds->dataRead();
            $this->projectData = $ds;
        }
        return $this->projectData->getCellValue($projectAttributeAlias, 0);
    }

    /**
     * Function for getting a value out of the host's data
     *
     * @param TaskInterface $task
     * @param string $option
     * @throws ActionInputMissingError
     * @return string
     */
    protected function getHostData(TaskInterface $task, string $option) : string
    {
        if ($this->hostData === null) {
            // TODO host name aus CLI-Parameter
            if ($task->hasParameter('host')) {
                $hostName = $task->getParameter('host');
            } else {
                $hostUid = $this->getHostUid($task);
            }
            
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.host');
            $ds->getColumns()->addMultiple([
                'data_connection',
                'last_build_deployed',
                'name',
                'operating_system',
                'path_abs_to_api',
                'path_rel_to_releases',
                'php_cli',
                'project',
                'stage'
            ]);
            $ds->addFilterFromString('UID', $hostUid, ComparatorDataType::EQUALS);
            $ds->addFilterFromString('name', $hostName, ComparatorDataType::EQUALS);
            $ds->dataRead();
            $this->hostData = $ds;
        }
        return $this->hostData->getCellValue($option, 0);
    }
    
    /**
     * Function for getting a value out of the build's data
     * 
     * @param TaskInterface $task
     * @param string $projectAttributeAlias
     * @return string
     */
    protected function getBuildData(TaskInterface $task, string $projectAttributeAlias): string
    {
        if ($this->buildData === null) {
            if ($task->hasParameter('build')) {
                $buildName = $task->getParameter('build');
            } else {
                $buildUid = $this->getBuildUid($task);
            }
            
            if (! $buildUid && $buildName === null) {
                throw new ActionInputMissingError($this, 'Cannot deploy build: missing build reference!', '784EI40');
            }
            
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.Deployer.build');
            $ds->getColumns()->addMultiple([
                'build_datatime',
                'build_recipe_path',
                'comment',
                'composer_auth_json',
                'composer_json',
                'log',
                'name',
                'notes',
                'project_oid',
                'status',
                'version'
            ]);
            $ds->addFilterFromString('UID', $buildUid, ComparatorDataType::EQUALS);
            $ds->addFilterFromString('name', $buildName, ComparatorDataType::EQUALS);
            $ds->dataRead();
            $this->buildData = $ds;
            $test = $this->buildData->getCellValue('name', 0);
        }
        return $this->buildData->getCellValue($projectAttributeAlias, 0);
    }
    
    /**
     *  
     * @param Taskinterface $task
     * @throws ActionInputMissingError
     * @return string
     */
    protected function getHostUid(Taskinterface $task) : string
    {
        $inputData = $this->getInputDataSheet($task);
        if ($col = $inputData->getColumns()->get('host')) {
            $hostUid = $col->getCellValue(0);
        } else {
            throw new ActionInputMissingError($this, 'TODO: no host given!');
        }
        return $hostUid;
    }
    
    /**
     * 
     * @param TaskInterface $task
     * @throws ActionInputMissingError
     * @return string
     */
    protected function getBuildUid(TaskInterface $task) : string
    {
        $inputData = $this->getInputDataSheet($task);
        if ($col = $inputData->getColumns()->get('build')) {
            $buildUid = $col->getCellValue(0);
        } else {
            throw new ActionInputMissingError($this, 'TODO: no build given!');
        }
        return $buildUid;
    }
 
    /**
     *
     * @param TaskInterface $task
     * @return DeployerSshConnector
     */
    protected function getSshConnection(TaskInterface $task) : DeployerSshConnector
    {
        $connectionUid = $this->getHostData($task, 'data_connection');
        return DataConnectionFactory::createFromModel($this->getWorkbench(), $connectionUid);
    }    
 
    /**
     * generates deploy data and creates deploy.php file
     *
     * @param TaskInterface $task
     * @param string $recipePath
     * @param string $buildFolder
     * @return string
     */
    protected function createDeployPhp(TaskInterface $task, string $basepath, string $buildFolder, string $sshConfigFilePath) : string
    {

        $stage = $this->getHostData($task, 'stage');
        $name = $this->getHostData($task, 'name');
        $absoluteSshConfigFilePath = $basepath . $sshConfigFilePath;
        $basicDeployPath = 'C:' . DIRECTORY_SEPARATOR . 'wamp' . DIRECTORY_SEPARATOR . $name;
        $buildsArchivesPath = $basepath . $buildFolder . DIRECTORY_SEPARATOR . $this->getFolderNameForBuilds();
        $phpPath = $this->getHostData($task, 'php_cli');
        $recipePath = $this->getDeployRecipeFile($task);
        
        
        $content = <<<PHP
<?php
namespace Deployer;

ini_set('memory_limit', '-1'); // deployment may exceed 128MB internal memory limit

require 'vendor/autoload.php'; // Or move it to deployer and automatically detect
require 'vendor/deployer/deployer/recipe/common.php';

// === Host ===
set('stage', '{$stage}');
set('host_short', '{$name}');
set('host_ssh_config', '{$absoluteSshConfigFilePath}');

// === Path definitions ===
set('basic_deploy_path', '{$basicDeployPath}');
set('relative_deploy_path', 'powerui');
set('builds_archives_path', '{$buildsArchivesPath}');
set('php_path', '{$phpPath}');

require '{$recipePath}';

PHP;
        
        $content_php = fopen($buildFolder . DIRECTORY_SEPARATOR . 'deploy.php', 'w');
        fwrite($content_php, $content);
        fclose($content_php);
        
        return $buildFolder . DIRECTORY_SEPARATOR . 'deploy.php';
    }
    
    
    
    public function getCliArguments(): array
    {
        return [
            (new ServiceParameter($this))
            ->setName('build')
            ->setDescription('Name of the build to deploy')
            ->setRequired(true),
            (new ServiceParameter($this))
            ->setName('host')
            ->setDescription('Identifier of the host to deploy on')
            ->setRequired(true)
        ];
    }

    public function getCliOptions(): array
    {
        return [];
    }

    
    /**
     * Prepares the folder structure needed to run the deployer command.
     *
     * @param TaskInterface $task
     * @return string
     */
    protected function prepareDeployerProjectFolder(TaskInterface $task) : string
    {
        $connection = $this->getSshConnection($task);
        
        //extract the data required for the SSH-connection
        $privateKey = $connection->getSshPrivateKey();
        $hostAlias = $connection->getAlias();
        $customOptions = $connection->getSshConfig();
        $hostName = $connection->getHostName();
        $user = $connection->getUser();
        $port = $connection->getPort();
        $host = $this->getHostData($task, 'name');
        
        //create /hosts/alias directory 
        $hostAliasFolderPath = $this->createHostFolderPath($task, $hostAlias);
        
        $basePath = $this->getBasePath();
        
        // ACHTUNG: id_rsa muss nur für PHP-user lesbar sein!
        $privateKeyFilePath = $this->createPrivateKeyFile($hostAliasFolderPath, $privateKey);
        $this->setPrivateKeyFilePermissions($privateKeyFilePath);
        
        //create known_hosts file
        $knownHostsFilePath = $this->createKnownHostsFile($hostAliasFolderPath);

        //get default ssh-config
        $defaultSshConfig = $this->getDefaultSshConfig($basePath, $host, $hostName, $user, $port, $privateKeyFilePath, $knownHostsFilePath);
        
        //merge the default options with the ones set in the dataconnection
        $sshConfig = array_merge($defaultSshConfig, $customOptions);
        
        //save the options to a file 
        $sshConfigFilePath = $this->createSshConfigFile($hostAliasFolderPath, $sshConfig);
        
        $this->createDeployPhp($task, $basePath, $this->getProjectFolderRelativePath($task), $sshConfigFilePath);

        
        return $hostAliasFolderPath;
    }
    
    /**
     *
     * @return string
     */
    protected function getFolderNameForHosts() : string
    {
        return 'hosts';
    }
    
    /**
     * 
     * @return string
     */
    protected function getFileNamePrivateKey() : string
    {
        return 'id_rsa';
    }
    
    /**
     * 
     * @return string
     */
    protected function getFileNameKnownHosts() : string
    {
        return 'known_hosts';
    }
    
    /**
     * 
     * @return string
     */
    protected function getFileNameSshConfig() : string
    {
        return 'ssh_config';
    }
   
    /**
     * Creates the folder structure of the directories needed for deployment. 
     * 
     * e.g. deployer\testBuild\hosts\hostAlias
     * 
     * @param Taskinterface $task
     * @param string $hostAlias
     * @return string
     */
    protected function createHostFolderPath(Taskinterface $task, string $hostAlias) : string
    {
        $projectFolder = $this->getProjectFolderRelativePath($task);
        
        $hostsFolderPath = $projectFolder
        . DIRECTORY_SEPARATOR . $this->getFolderNameForHosts();
        
        $hostAliasFolderPath = $hostsFolderPath
        . DIRECTORY_SEPARATOR . $hostAlias;
        
        Filemanager::pathConstruct($hostAliasFolderPath);
        
        return $hostAliasFolderPath;
    }
    
    /**
     * Creates and fills the file for the privatekey of the SSH connection. Returns the path to the file.
     * @param string $hostAliasFolderPath
     * @param string $privateKey
     * @return string
     */
    protected function createPrivateKeyFile(string $hostAliasFolderPath, string $privateKey) :string
    {
   
        $privateKeyFileDirectory = $hostAliasFolderPath . DIRECTORY_SEPARATOR . $this->getFileNamePrivateKey();
        
        $privateKeyFile = fopen($privateKeyFileDirectory, 'w');
        fwrite($privateKeyFile, $privateKey);
        fclose($privateKeyFile);

        return $privateKeyFileDirectory;
    }
    
    /**
     * This function sets the right permissions for the file containing the private ssh-key.
     * If the parameters are not set right, the ssh-call of the deployment process might not work.
     * The actual functions to set the permissions are depending on the operating system the deployer is executed on.
     * 
     * @param string $privateKeyFileDirectory
     * @param string $hostOperatingSystem
     */
    protected function setPrivateKeyFilePermissions(string $privateKeyFileDirectory)
    {
        $hostOperatingSystem = PHP_OS;
        
        switch ($hostOperatingSystem){
            // running on windows:
            case (strtoupper(substr($hostOperatingSystem, 0, 3)) === 'WIN') :
                $commandList = [
                    'icacls ' . $privateKeyFileDirectory . ' /c /t /inheritance:d',
                    'icacls ' . $privateKeyFileDirectory . ' /c /t /grant %username%:F',
                    'icacls ' . $privateKeyFileDirectory . ' /c /t /grant SYSTEM:WRX',
                    'icacls ' . $privateKeyFileDirectory . ' /c /t /remove Administrator "Authenticated Users" BUILTIN Everyone System Users',
                    'icacls ' . $privateKeyFileDirectory . ' /verify'
                ];
                
                foreach($commandList as $cmd){
                    $process = Process::fromShellCommandline($cmd);
                    $process->start();
                }
                break;
                
            // if there is no case for the used OS, just assume its linux
            default:
                chmod($privateKeyFileDirectory, 0600);
                break;
        }
        
        return;
    }
       
    /**
     * 
     * @param string $hostAliasFolderPath
     * @return string
     */
    protected function createKnownHostsFile(string $hostAliasFolderPath) : string
    {
        $knownHostsFileDirectory = $hostAliasFolderPath . DIRECTORY_SEPARATOR . $this->getFileNameKnownHosts();
        $knownHostsFile = fopen($knownHostsFileDirectory, 'w');
        fwrite($knownHostsFile, '');
        fclose($knownHostsFile);
        
        return $knownHostsFileDirectory;
    }
    
    /**
     * Creates the file including the SSH-configuration from an array including the parameters. 
     * 
     * @param string $hostAliasFolderPath
     * @param array $sshConfig
     * @return string
     */
    protected function createSshConfigFile( string $hostAliasFolderPath, array $sshConfig) : string
    {
        $sshConfigFileDirectory = $hostAliasFolderPath . DIRECTORY_SEPARATOR . $this->getFileNameSshConfig();
        
        $sshConfigString = $this->stringifySshConfigArray($sshConfig);

        $sshConfigFile = fopen($sshConfigFileDirectory, 'w');
        fwrite($sshConfigFile, $sshConfigString);
        fclose($sshConfigFile);
        
        return $sshConfigFileDirectory;
    }
    
    /**
     *
     * @param string $pathToHostFolder
     * @param string $hostName
     * @param string $user
     * @param string $port
     * @return array
     */
    protected function getDefaultSshConfig(string $basePath, string $host, string $hostName, string $user, string $port, string $privateKeyFilePath, string $knownHostsFilePath) : array
    {
        
        return [
             'Host' => $host,
             'HostName' => $hostName, // 10.57.2.40 // Kommt aus Dataconnection
             'User' => $user, //SFCKOENIG\ITSaltBI // Kommt aus DataConnection
             'port' => $port, //22 // Kommt aus DataConnection
             'PreferredAuthentications' => 'publickey',
             'StrictHostKeyChecking' => 'no',
             'IdentityFile' => $basePath . $privateKeyFilePath, //C:\wamp\www\sfckoenig\exface\deployer\sfc\hosts\powerui\id_rsa
             'UserKnownHostsFile' => $basePath . $knownHostsFilePath //C:\wamp\www\sfckoenig\exface\deployer\sfc\hosts\powerui\known_hosts
        ];
    }
    
    /**
     * Creates a stringified version of the ssh-config data, which the deployer can read.
     * 
     * @param array $sshConfig
     * @return string
     */
    protected function stringifySshConfigArray(array $sshConfig) : string
    {
        $sshConfigKeys = array_keys($sshConfig);
        $sshConfigValues = array_values($sshConfig);
        
        $sshConfigString = '';
        
        for($entry = 0; $entry < sizeof($sshConfig); $entry++){
            $sshConfigString .= $sshConfigKeys[$entry]
            . ' '
                . $sshConfigValues[$entry]
                . "\n";
        }
        
        return $sshConfigString;
    }
    
    /**
     * 
     * @param TaskInterface $task
     * @return string
     */
    protected function getDeployRecipeFile(TaskInterface $task): string
    {
        $recipe = $this->getProjectData($task, 'deployment_recipe');
        
        switch ($recipe) {
            case BuildRecipeDataType::CUSTOM_BUILD:
                return $this->getProjectData($task, 'deployment_recipe_custom_path');
            default:
                $recipiesBasePath = Filemanager::FOLDER_NAME_VENDOR . DIRECTORY_SEPARATOR . $this->getApp()->getDirectory() . DIRECTORY_SEPARATOR . 'Recipes' . DIRECTORY_SEPARATOR . 'Deploy' . DIRECTORY_SEPARATOR;
                return $recipiesBasePath . $recipe . '.php';
        }
    }
    
    /**
     * 
     * @param TaskInterface $task
     * @param string $baseFolder
     * @param DataSheetInterface $deployData
     * @return string
     */
    protected function prepareDeployerTask(TaskInterface $task, string $baseFolder, DataSheetInterface $deployData) : string
    {
        $cmd = " -f=" . $this->getProjectFolderRelativePath($task) . DIRECTORY_SEPARATOR . 'deploy.php';
        
        // Get deployer recipe file path
        $recipePath = $this->getDeployRecipeFile($task);
        $deployerTaskName = basename($recipePath, '.php');
        
        $cmd .= ' ' . $deployerTaskName;

        $cmd .= ' --build=' . $this->getBuildData($task, 'name') . '.tar.gz';
        
        return $cmd; // testbuild\deploy.php LocalBldSshSelfExtractor --build=1.0.1...tar.gz
    }
    
    /**
     * 
     * @return string
     */
    protected function getBasePath() : string
    {
        return $this->getWorkbench()->filemanager()->getPathToBaseFolder() . DIRECTORY_SEPARATOR;
    }
    
    /**
     * 
     * @return int
     */
    protected function getTimeout() : int
    {
        return $this->timeout;
    }
    
    /**
     * Timeout for the Deploy command.
     *
     * @uxon-property timeout
     * @uxon-type integer
     * @uxon-default 600
     *
     * @param int $seconds
     * @return Build
     */
    public function setTimeout(int $seconds) : Build
    {
        $this->timeout = $seconds;
        return $this;
    }
    
    /**
     * Deletes every temporary file created in the deployment-process
     * @param string $projectFolder
     * @param string $hostAliasFolderPath
     */
    protected function cleanupFiles(string $projectFolder, string $hostAliasFolderPath)
    {
        $stagedFiles = [
            $projectFolder . DIRECTORY_SEPARATOR . 'deploy.php',
            $hostAliasFolderPath . DIRECTORY_SEPARATOR . $this->getFileNameKnownHosts(),
            $hostAliasFolderPath . DIRECTORY_SEPARATOR . $this->getFileNamePrivateKey(),
            $hostAliasFolderPath . DIRECTORY_SEPARATOR . $this->getFileNameSshConfig()
        ];
        
        $stagedDirectories = [
            $hostAliasFolderPath,
            $projectFolder . DIRECTORY_SEPARATOR . $this->getFolderNameForHosts()
        ];
        
        //delete files first
        foreach($stagedFiles as $file){
            unlink($file);
        }
        
        //delete directories last
        foreach($stagedDirectories as $dir){
            rmdir($dir);
        } 
    }
}