<?php

namespace AdvancedLearning\AWSVideos\Config;

use \Config;
use \Deprecation;

/**
 * 3.* compatible configurable trait.
 *
 * Provides extensions to this object to integrate it with standard config API methods.
 *
 * Note that all classes can have configuration applied to it, regardless of whether it
 * uses this trait.
 */
trait Configurable
{

    /**
     * Get a configuration accessor for this class. Short hand for Config::inst()->get($this->class, .....).
     *
     * @return \Config_ForClass
     */
    public static function config()
    {
        return Config::inst()->forClass(get_called_class());
    }

    /**
     * Get inherited config value.
     *
     * @param string $name Name of static variable/config key.
     * @return mixed
     */
    public function stat($name)
    {
        Deprecation::notice('5.0', 'Use ->get');
        return self::config()->get($name);
    }

    /**
     * Update the config value for a given property
     *
     * @param string $name  Name of static to set.
     * @param mixed  $value Value to set static to.
     * @return $this
     */
    public function set_stat($name, $value)
    {
        Deprecation::notice('5.0', 'Use ->config()->set()');
        self::config()->update($name, $value);
        return $this;
    }
}