<?php

namespace ZfModule\Controller;

use EdpGithub\Client;
use EdpGithub\Collection\RepositoryCollection;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\View\Model\JsonModel;
use ZfModule\Mapper;
use ZfModule\Service;

class IndexController extends AbstractActionController
{
    /**
     * @var Mapper\Module
     */
    private $moduleMapper;

    /**
     * @var Service\Module
     */
    private $moduleService;

    /**
     * @var Client
     */
    private $githubClient;

    /**
     * @param Mapper\Module $moduleMapper
     * @param Service\Module $moduleService
     * @param Client $githubClient
     */
    public function __construct(Mapper\Module $moduleMapper, Service\Module $moduleService, Client $githubClient)
    {
        $this->moduleMapper = $moduleMapper;
        $this->moduleService = $moduleService;
        $this->githubClient = $githubClient;
    }

    public function viewAction()
    {
        $vendor = $this->params()->fromRoute('vendor', null);
        $module = $this->params()->fromRoute('module', null);

        $sl = $this->getServiceLocator();

        $result = $this->moduleMapper->findByName($module);
        if (!$result) {
            $this->getResponse()->setStatusCode(404);
            return;
        }

        /* @var $cache StorageInterface */
        $cache = $sl->get('zfmodule_cache');

        $cacheKey = 'module-view-' . $vendor . '-' . $module;

        $repository = json_decode($this->githubClient->api('repos')->show($vendor, $module));
        $httpClient = $this->githubClient->getHttpClient();
        $response= $httpClient->getResponse();
        if ($response->getStatusCode() == 304 && $cache->hasItem($cacheKey)) {
            return $cache->getItem($cacheKey);
        }

        $readme = $this->githubClient->api('repos')->readme($vendor, $module);
        $readme = json_decode($readme);
        $repository = json_decode($this->githubClient->api('repos')->show($vendor, $module));

        try {
            $license = $this->githubClient->api('repos')->content($vendor, $module, 'LICENSE');
            $license = json_decode($license);
            $license = base64_decode($license->content);
        } catch (\Exception $e) {
            $license = 'No license file found for this Module';
        }

        try {
            $composerJson = $this->githubClient->api('repos')->content($vendor, $module, 'composer.json');
            $composerConf = json_decode($composerJson);
            $composerConf = base64_decode($composerConf->content);
            $composerConf = json_decode($composerConf, true);
        } catch (\Exception $e) {
            $composerConf = 'No composer.json file found for this Module';
        }

        $viewModel = new ViewModel(array(
            'vendor' => $vendor,
            'module' => $module,
            'repository' => $repository,
            'readme' => base64_decode($readme->content),
            'composerConf' => $composerConf,
            'license' => $license,
        ));

        $cache->setItem($cacheKey, $viewModel);

        return $viewModel;
    }

    public function indexAction()
    {
        if (!$this->zfcUserAuthentication()->hasIdentity()) {
            return $this->redirect()->toRoute('zfcuser/login');
        }

        $params = array(
            'type'      => 'all',
            'per_page'  => 100,
            'sort'      => 'updated',
            'direction' => 'desc',
        );

        /* @var RepositoryCollection $repos */
        $repos = $this->githubClient->api('current_user')->repos($params);

        $identity = $this->zfcUserAuthentication()->getIdentity();
        $cacheKey = 'modules-user-' . $identity->getId();

        $repositories = $this->fetchModules($repos, $cacheKey);

        $viewModel = new ViewModel(array('repositories' => $repositories));
        $viewModel->setTerminal(true);
        return $viewModel;
    }

    public function organizationAction()
    {
        if (!$this->zfcUserAuthentication()->hasIdentity()) {
            return $this->redirect()->toRoute('zfcuser/login');
        }

        $owner = $this->params()->fromRoute('owner', null);
        $params = array(
            'per_page'  => 100,
            'sort'      => 'updated',
            'direction' => 'desc',
        );

        /* @var RepositoryCollection $repos */
        $repos = $this->githubClient->api('user')->repos($owner, $params);

        $identity = $this->zfcUserAuthentication()->getIdentity();
        $cacheKey = 'modules-organization-' . $identity->getId() . '-' . $owner;

        $repositories = $this->fetchModules($repos, $cacheKey);
        $viewModel = new ViewModel(array('repositories' => $repositories));
        $viewModel->setTerminal(true);
        $viewModel->setTemplate('zf-module/index/index.phtml');
        return $viewModel;
    }

    /**
     * @param RepositoryCollection $repos
     * @param string $cacheKey
     * @return array
     */
    public function fetchModules(RepositoryCollection $repos, $cacheKey)
    {
        $cacheKey .= '-github';
        $sl = $this->getServiceLocator();

        /* @var $cache StorageInterface */
        $cache = $sl->get('zfmodule_cache');

        $repositories = array();

        foreach ($repos as $repo) {
            $isModule = $this->moduleService->isModule($repo);
            //Verify if repos have been modified
            $httpClient = $this->githubClient->getHttpClient();
            /* @var $response \Zend\Http\Response */
            $response = $httpClient->getResponse();

            $hasCache = $cache->hasItem($cacheKey);

            if ($response->getStatusCode() == 304 && $hasCache) {
                $repositories = $cache->getItem($cacheKey);
                break;
            }

            if (!$repo->fork && $repo->permissions->push && $isModule && !$this->moduleMapper->findByName($repo->name)) {
                $repositories[] = $repo;
                $cache->removeItem($cacheKey);
            }
        }

        //save list of modules to cache
        $cache->setItem($cacheKey, $repositories);

        return $repositories;
    }

    /**
     * This function is used to submit a module from the site
     * @throws Exception\UnexpectedValueException
     * @return
     **/
    public function addAction()
    {
        if (!$this->zfcUserAuthentication()->hasIdentity()) {
            return $this->redirect()->toRoute('zfcuser/login');
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            $repo = $request->getPost()->get('repo');
            $owner  = $request->getPost()->get('owner');

            $repository = $this->githubClient->api('repos')->show($owner, $repo);
            $repository = json_decode($repository);

            if (!($repository instanceof \stdClass)) {
                throw new Exception\RuntimeException(
                    'Not able to fetch the repository from github due to an unknown error.',
                    500
                );
            }

            if (!$repository->fork && $repository->permissions->push) {
                if ($this->moduleService->isModule($repository)) {
                    $module = $this->moduleService->register($repository);
                    $this->flashMessenger()->addMessage($module->getName() .' has been added to ZF Modules');
                } else {
                    throw new Exception\UnexpectedValueException(
                        $repository->name . ' is not a Zend Framework Module',
                        403
                    );
                }
            } else {
                throw new Exception\UnexpectedValueException(
                    'You have no permission to add this module. The reason might be that you are' .
                    'neither the owner nor a collaborator of this repository.',
                    403
                );
            }
        } else {
            throw new Exception\UnexpectedValueException(
                'Something went wrong with the post values of the request...'
            );
        }

        $this->clearModuleCache();

        return $this->redirect()->toRoute('zfcuser');
    }

    public function clearModuleCache()
    {
        $sl = $this->getServiceLocator();
        $cache = $sl->get('zfmodule_cache');
        $identity = $this->zfcUserAuthentication()->getIdentity();

        $tags = array($identity->getUsername() . '-' . $identity->getId());
        $cache->clearByTags($tags);
    }

    /**
     * This function is used to remove a module from the site
     * @throws Exception\UnexpectedValueException
     * @return
     **/
    public function removeAction()
    {
        if (!$this->zfcUserAuthentication()->hasIdentity()) {
            return $this->redirect()->toRoute('zfcuser/login');
        }

        $request = $this->getRequest();
        if ($request->isPost()) {
            $repo = $request->getPost()->get('repo');
            $owner  = $request->getPost()->get('owner');

            $repository = $this->githubClient->api('repos')->show($owner, $repo);
            $repository = json_decode($repository);

            if (!$repository instanceof \stdClass) {
                throw new Exception\RuntimeException(
                    'Not able to fetch the repository from github due to an unknown error.',
                    500
                );
            }

            if (!$repository->fork && $repository->permissions->push) {
                $module = $this->moduleMapper->findByUrl($repository->html_url);
                if ($module instanceof \ZfModule\Entity\Module) {
                    $module = $this->moduleMapper->delete($module);
                    $this->flashMessenger()->addMessage($repository->name .' has been removed from ZF Modules');
                } else {
                    throw new Exception\UnexpectedValueException(
                        $repository->name . ' was not found',
                        403
                    );
                }
            } else {
                throw new Exception\UnexpectedValueException(
                    'You have no permission to add this module. The reason might be that you are' .
                    'neither the owner nor a collaborator of this repository.',
                    403
                );
            }
        } else {
            throw new Exception\UnexpectedValueException(
                'Something went wrong with the post values of the request...'
            );
        }

        $this->clearModuleCache();
        return $this->redirect()->toRoute('zfcuser');
    }
}
