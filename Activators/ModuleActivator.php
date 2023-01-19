<?php

namespace Modules\Isite\Activators;

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository as Config;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Module;
use Modules\Isite\Entities\Module as IModule;
use Illuminate\Support\Str;

class ModuleActivator implements ActivatorInterface
{
  /**
   * Laravel cache instance
   *
   * @var CacheManager
   */
  private $cache;
  
  /**
   * Laravel Filesystem instance
   *
   * @var Filesystem
   */
  private $files;
  
  /**
   * Laravel config instance
   *
   * @var Config
   */
  private $config;
  
  /**
   * @var string
   */
  private $cacheKey;
  
  /**
   * @var string
   */
  private $cacheLifetime;
  
  /**
   * Array of modules activation statuses
   *
   * @var array
   */
  public $modulesStatuses;
  
  /**
   * File used to store activation statuses
   *
   * @var string
   */
  private $statusesFile;
  
  public function __construct(Container $app)
  {
    $this->cache = $app['cache'];
    $this->files = $app['files'];
    $this->config = $app['config'];
    $this->statusesFile = $this->config('statuses-file');
    $this->cacheKey = $this->config('cache-key');
    $this->cacheLifetime = $this->config('cache-lifetime');
    $this->modulesStatuses = $this->getModulesStatuses();
  }
  
  /**
   * Get the path of the file where statuses are stored
   *
   * @return string
   */
  public function getStatusesFilePath(): string
  {
    return $this->statusesFile;
  }
  
  /**
   * @inheritDoc
   */
  public function reset(): void
  {
    if ($this->files->exists($this->statusesFile)) {
      $this->files->delete($this->statusesFile);
    }
    $this->modulesStatuses = [];
    $this->flushCache();
  }
  
  /**
   * @inheritDoc
   */
  public function enable(Module $module): void
  {
  
    $this->setActiveByName($module->getName(), true);
  }
  
  /**
   * @inheritDoc
   */
  public function disable(Module $module): void
  {
    $this->setActiveByName($module->getName(), false);
  }
  
  public function findModuleByName($name){
   
    //cached module entity for 30 days
    
    $module = Cache::store(config("cache.default"))->remember((isset(tenant()->id) ? "organization".tenant()->id."_" : "").'isite_module_'. Str::lower($name).(tenant()->id ?? ""), 60*60*24*30, function () use ($name) {

      return IModule::where("alias", Str::lower($name))->first() ?? "";
    });
    
    if(!isset($module->id)){
      $lowerName = Str::lower($name);
   
      //if(in_array($lowerName,json_decode($this->files->get($this->statusesFile), true))){
        if(in_array($lowerName,config("asgard.core.config.CoreModules"))){
        $module = new IModule([
          "alias" => Str::lower($name),
          "name" => $name,
          "enabled" => true
        ]);
      } else{
        return false;
      }
    }
  
    return $module;
  }
  /**
   * @inheritDoc
   */
  public function hasStatus(Module $module, bool $status): bool
  {
    
    $module = $this->findModuleByName($module->getName());
    
    return $module->enabled ?? false;
  }
  
  /**
   * @inheritDoc
   */
  public function setActive(Module $module, bool $active): void
  {

    $this->setActiveByName($module->getName(), $active);
  }
  
  public function throwModuleIfNotExist($module,$name){
    if(!isset($module->id) && !isset($module->name)) throw new \Exception("The module $name doesn't exist in the database",400);
  }
  /**
   * @inheritDoc
   */
  public function setActiveByName(string $name, bool $status): void
  {

    $module = $this->findModuleByName($name);
   
    if(!isset($module->id)){
      $lowerName = Str::lower($name);
      
      if(in_array($lowerName,json_decode($this->files->get($this->statusesFile), true))){
        $module = new IModule([
          "alias" => Str::lower($name),
          "name" => $name,
          "enabled" => true
        ]);

      }
      
    }
 

    $this->throwModuleIfNotExist($module,$name);
    
    $module->enabled = $status;
   
    $module->save();
    
    $this->modulesStatuses[$name] = $status;
    $this->writeJson();
    $this->flushCache();
  }
  
  /**
   * @inheritDoc
   */
  public function delete(Module $module): void
  {
    $module = $this->findModuleByName($module->getName());
  
    $this->throwModuleIfNotExist($module);
    
    $module->delete();
  }
  
  /**
   * Writes the activation statuses in a file, as json
   */
  private function writeJson(): void
  {
    $this->files->put($this->statusesFile, json_encode($this->modulesStatuses, JSON_PRETTY_PRINT));
  }
  
  /**
   * Reads the json file that contains the activation statuses.
   * @return array
   * @throws FileNotFoundException
   */
  private function readJson(): array
  {
    $statuses = [];

    $allModules = Cache::store(config("cache.default"))->remember((isset(tenant()->id) ? "organization".tenant()->id."_" : "").'isite_module_all_modules'.(tenant()->id ?? ""), 60*60*24*30, function () {
      return IModule::all() ?? "";
    });

    foreach ($allModules as $module){
      $statuses[Str::ucfirst($module->alias)] = $module->enabled ? true : false;
    }
    $statuses["Core"] = true;
 
    return ($statuses);
    
  }
  
  /**
   * Get modules statuses, either from the cache or from
   * the json statuses file if the cache is disabled.
   * @return array
   * @throws FileNotFoundException
   */
  private function getModulesStatuses(): array
  {
    if (!$this->config->get('modules.cache.enabled')) {
      return $this->readJson();
    }
    
    return $this->cache->remember($this->cacheKey, $this->cacheLifetime, function () {
      return $this->readJson();
    });
  }
  
  /**
   * Reads a config parameter under the 'activators.file' key
   *
   * @param  string $key
   * @param  $default
   * @return mixed
   */
  private function config(string $key, $default = null)
  {
    return $this->config->get('modules.activators.file.' . $key, $default);
  }
  
  /**
   * Flushes the modules activation statuses cache
   */
  private function flushCache(): void
  {
    $this->cache->forget($this->cacheKey);
  }
}