<?php

/*
 * Datawrapper Plugin Manager
 * --------------------------
 *
 * Examples:
 *
 * Install all available plugins
 *    php plugin.php install "*"
 *
 * Disable a plugins
 *    php plugin.php diable foo
 *
 * Uninstall a plugin
 *    php plugin.php uninstall foo
 *
 * Batch enable some plugins
 *    php plugin.php enable "visualization-*"
 *
 */


define('ROOT_PATH', dirname(dirname(__FILE__)) . '/');

define('NO_SLIM', 1);

date_default_timezone_set('Europe/Berlin');

require_once ROOT_PATH . 'lib/bootstrap.php';

$cmd = $argv[1];

/*
 * list installed plugins
 */
function list_plugins() {
    $plugins = PluginQuery::create()->find();
    print "\n";
    foreach ($plugins as $plugin) {
        print $plugin->getEnabled() ? "\033[1;32mENABLED" : "\033[1;31mDISABLED";
        print "\033[m ".$plugin->getName();
        // check for un-committed changes
        $info = $plugin->getInfo();
        if (!empty($info['repository']) &&
            $info['repository']['type'] == 'git' &&
            file_exists($plugin->getPath() . '.git/config')) {

            $ret = array();
            exec('cd '.$plugin->getPath().'; git fetch 2>&1', $ret, $err);
            $ret = array();
            exec('cd '.$plugin->getPath().'; git status -bs 2>&1', $ret, $err);
            $outdated = strpos($ret[0], '[behind') > -1;
            $modified = $deleted = $new = $added = false;
            foreach ($ret as $line) {
                # code...
                if (substr($line, 0, 2) == '??') $new = true;
                if ($line[1] == 'M') $modified = true;
                if ($line[0] == 'A') $added = true;
                if ($line[1] == 'D') $deleted = true;
            }
            print " ";
            if ($new) print "\033[0;34m✭ ";
            if ($added) print "\033[0;32m✚ ";
            if ($modified) print "\033[1;34m✹ ";
            if ($deleted) print "\033[0;31m✖ ";
            if ($outdated) print "\033[1;32m➤ ";

        }
        print "\033[m\n";
    }
    _apply("*", function($id) {
        $plugin = PluginQuery::create()->findPk($id);
        if (!$plugin) print "$id :  NOT INSTALLED\n";
    });
}

/*
 * removes plugins from db that have no files installed anymore
 */
function clean() {
    $plugins = PluginQuery::create()->find();
    foreach ($plugins as $plugin) {
        if (!file_exists($plugin->getPath() . 'package.json')) {
            $plugin->delete();
            print $plugin->getId()." deleted from database.\n";
        }
    }
}

/*
 * installs plugins
 */
function install($pattern) {
    if (is_git_url($pattern)) {
        // checkout git repository into tmp directory
        // ROOT_PATH . "plugins" . DIRECTORY_SEPARATOR
        $tmp_name = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tmp-'.time();
        exec('git clone '.$pattern.' '.$tmp_name.' 2>&1', $ret, $err);
        $pkg_info = $tmp_name . DIRECTORY_SEPARATOR . 'package.json';
        if (file_exists($pkg_info)) {
            try {
                $pkg_info = json_decode(file_get_contents($pkg_info), true);
            } catch (Error $e) {
                print 'Not a valid plugin: package.json could not be read.';
                return;
            }
            if (!empty($pkg_info['name'])) {
                $plugin_path = ROOT_PATH . 'plugins' . DIRECTORY_SEPARATOR . $pkg_info['name'];
                if (!file_exists($plugin_path)) {
                    rename($tmp_name, $plugin_path);
                    $pattern = $pkg_info['name']; // proceed with this id
                } else {
                    print 'Plugin '.$pkg_info['name'].' is already installed';
                    return;
                }
            } else {
                print 'No package name specified in package.json.';
                return;
            }
        } else {
            print 'No package.json found';
        }
    }
    _apply($pattern, function($id) {
        $tmp = new Plugin();
        $tmp->setId($id);

        // check if plugin files exist
        if (!file_exists($tmp->getPath())) {
            print "No plugin found with that name. Skipping.\n";
            return false;
        }
        if (!file_exists($tmp->getPath() . 'package.json')) {
            print "Path exists, but no package.json found. Skipping.\n";
            return false;
        }
        // check if plugin is already installed
        $plugin = PluginQuery::create()->findPk($id);
        if ($plugin) {
            _loadPluginClass($plugin)->install();
            print "Re-installed plugin $id.\n";
        } else {
            $plugin = new Plugin();
            $plugin->setId($id);
            $plugin->save();
            _loadPluginClass($plugin)->install();
            print "Installed plugin $id.\n";
        }
    });
}

/*
 * uninstalls plugins
 */
function uninstall($pattern) {
    _apply($pattern, function($id) {
        $tmp = new Plugin();
        $tmp->setId($id);

        $plugin = PluginQuery::create()->findPk($id);
        if (!$plugin || $plugin && !file_exists($plugin->getPath())) {
            print "Plugin $id not found. Skipping.\n";
            return false;
        }

        if (!$plugin) {
            $plugin = new Plugin();
            $plugin->setId($id);
        }
        _loadPluginClass($plugin)->uninstall();
        print "Uninstalled plugin $id.\n";
    });
}

/*
 * enable plugins
 */
function enable($pattern) {
    _apply($pattern, function($id) {
        $plugin = PluginQuery::create()->findPk($id);
        if (!$plugin) {
            print "Plugin $id is not installed. Skipping.\n";
            return false;
        }
        if (!$plugin->getEnabled()) {
            $plugin->setEnabled(true);
            $plugin->save();
            print "Enabled plugin $id.\n";
        } else {
            print "Plugin $id is already enabled. Skipping.\n";
        }
    });
}

/*
 * disable plugins
 */
function disable($pattern) {
    _apply($pattern, function($id) {
        $plugin = PluginQuery::create()->findPk($id);
        if (!$plugin) {
            print "Plugin $id is not installed. Skipping.\n";
            return false;
        }
        if ($plugin->getEnabled()) {
            $plugin->setEnabled(false);
            $plugin->save();
            print "Disabled plugin $id.\n";
        } else {
            print "Plugin $id is already disabled. Skipping.\n";
        }
    });
}

/*
 * update plugins from git repository
 */
function update($pattern) {
    _apply($pattern, function($id) {
        $plugin = new Plugin();
        $plugin->setId($id);
        $repo = $plugin->getRepository();
        if ($repo) {
            if ($repo['type'] == 'git') {
                if (file_exists($plugin->getPath() . '.git/config')) {
                    $ret = array();
                    exec('cd '.$plugin->getPath().'; git pull origin master 2>&1', $ret, $err);
                    if ($ret[count($ret)-1] == 'Already up-to-date.') {
                        print "Plugin $id is up-to-date.\n";
                    } else {
                        print "Updated plugin $id.\n";
                        install($id);
                    }
                } else {
                    print "Skipping $id: Not a valid Git repository.\n";
                }
            } else {
                print "Skipping $id: Unhandled repository type ".$repo['type'].".\n";
            }
        } else {
            if (file_exists($plugin->getPath() . '.git/config')) {
                print "Skipping $id: No repository information found in package.json.\n";
            }
        }
    });
}

/*
 * Reinstall all installed plugins. Usefull for development
 */
function reload() {
    $plugins = PluginQuery::create()->find();
    foreach ($plugins as $plugin) {

        if (file_exists($plugin->getPath() . 'package.json')) {
            if ($plugin->getEnabled()) {
                _loadPluginClass($plugin)->install();
                print $plugin->getId()." reinstalled\n";
            }
        }
    }
}

function health_check() {
    $plugins = PluginQuery::create()->find();
    $core_info = json_decode(file_get_contents(ROOT_PATH . 'package.json'), true);
    $installed = array('core' => $core_info['version']);
    $dependencies = array();
    $WARN = "\033[1;31mWARNING:\033[1;33m";
    ob_start();
    foreach ($plugins as $plugin) {
        if (file_exists($plugin->getPath() . 'package.json')) {
            $info = json_decode(file_get_contents($plugin->getPath() . 'package.json'), true);
            if (empty($info)) {
                print $WARN.' package.json could not be read: '.$plugin->getId()."\n";
            } else {
                if (empty($info['version'])) {
                    print $WARN.' plugin has no version: '.$plugin->getId()."\n";
                    $info['version'] = true;
                } else {
                    $installed[$plugin->getId()] = $info['version'];
                }
                if (!empty($info['dependencies'])) {
                    $dependencies[$plugin->getId()] = $info['dependencies'];
                }
            }
        }
    }
    foreach ($dependencies as $id => $deps) {
        foreach ($deps as $dep_id => $dep_ver) {
            if (empty($installed[$dep_id]) && $dep_id != 'core') {
                print $WARN.' '.$id.' depends on a missing plugin: '.$dep_id."\n";
            } else {
                if (version_compare($installed[$dep_id], $dep_ver) < 0) {
                    print $WARN.' we need at least version '.$dep_ver.' of '.$dep_id."\n";
                }
            }
        }
    }
    $out = ob_get_contents();
    ob_end_clean();
    if (!empty($out)) {
        print $out;
        print "\007\033[m";
    }
}

function is_git_url($url) {
    return substr($url, -4) == ".git" || substr($url, 4) == 'git@';
}

switch ($cmd) {
    case 'list': list_plugins(); break;
    case 'clean': clean(); break;
    case 'reload': reload(); break;
    case 'install': install($argv[2]); break;
    case 'uninstall': uninstall($argv[2]); break;
    case 'enable': enable($argv[2]); break;
    case 'disable': disable($argv[2]); break;
    case 'update': update($argv[2]); break;
    case 'check': break;
    default:
        print 'Unknown command '.$cmd."\n";
}

health_check();

exit();


function _apply($pattern, $func) {
    $plugin_ids = array();
    if (strpos($pattern, '*') > -1) {
        foreach (glob(ROOT_PATH . "plugins" . DIRECTORY_SEPARATOR . $pattern . DIRECTORY_SEPARATOR . "package.json") as $filename) {
            $d = dirname($filename);
            $d = substr($d, strrpos($d, DIRECTORY_SEPARATOR)+1);
            $plugin_ids[] = $d;
        }
    } else {
        $plugin_ids[] = $pattern;
    }
    sort($plugin_ids);
    foreach ($plugin_ids as $plugin_id) {
        $func($plugin_id);
    }
}

function _loadPluginClass($plugin) {
    if (file_exists($plugin->getPath() . 'plugin.php')) {
        require_once $plugin->getPath() . 'plugin.php';
        $className = $plugin->getClassName();
        return new $className();
    }
    // no plugin.php
    return new DatawrapperPlugin($plugin->getName());
}
