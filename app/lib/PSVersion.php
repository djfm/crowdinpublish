<?php

class PSVersion
{
	public static function lock($version_number, callable $cb)
	{
		$lock_path = storage_path() . '/tmp/' . $version_number . '.lock';

		if (!is_dir(basename($lock_path)) && !@mkdir(basename($lock_path), 0777))
			return array(
				'success' => false,
				'message' => sprintf('Could not create dir `%s`.', basename($lock_path))
			);

		$h = fopen($lock_path, 'w');

		if (!$h)
			return array(
				'success' => false,
				'message' => sprintf('Could not create lock file `%s`.', $lock_path)
			);

		if (flock($h, LOCK_EX | LOCK_NB))
		{
			$result = call_user_func($cb);
			flock($h, LOCK_UN);
			fclose($h);
			return $result;
		}
		else
			return array(
				'success' => false,
				'message' => sprintf(
					'Could not acquire lock on `%s`. If this persists, maybe the file needs to be removed manually.',
					$lock_path
				)
			);
		
	}

	public static function getConfig()
	{
		$config_path = Config::get('crowdinpublish.crowdinator_path') . '/config.json';
		if (!file_exists($config_path))
			return array('success' => false, 'message' => sprintf('Config file `%s` not found.', $config_path));

		if (!is_readable($config_path))
			return array('success' => false, 'message' => sprintf('Config file `%s` cannot be read.', $config_path));

		$config = json_decode(file_get_contents($config_path), true);

		return array('success' => true, 'data' => $config);
	}

	public static function writeConfig($config)
	{
		$config_path = Config::get('crowdinpublish.crowdinator_path') . '/config.json';
		if (!@file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX))
		{
			return array('success' => false, 'message' => sprintf('Could not write config to `%s`.', $config_path));
		}

		return array('success' => true);
	}

	public static function updateGlobalConfigForVersion($version_number)
	{
		$config = static::getConfig();
		if (!$config['success'])
			return $config;

		$config = $config['data'];

		if (!isset($config['versions']))
			$config['versions'] = array();

		if (!isset($config['versions'][$version_number]))
		{
			$config['versions'][$version_number] = array(
				'publish' => '*',
				'actions' => array('publish_strings', 'build_packs', 'publish_all_packs')
			);
		}

		return static::writeConfig($config);
	}

	public static function getVersionConfig($version_number)
	{
		$config_path = Config::get('crowdinpublish.crowdinator_path') . '/versions/' . $version_number . '/config.json';
		if (!file_exists($config_path))
			return array('success' => false, 'message' => sprintf('Config file `%s` not found.', $config_path));

		if (!is_readable($config_path))
			return array('success' => false, 'message' => sprintf('Config file `%s` cannot be read.', $config_path));

		$config = json_decode(file_get_contents($config_path), true);

		return array('success' => true, 'data' => $config);
	}

	public static function writeVersionConfig($version_number, $conf)
	{
		$config_path = Config::get('crowdinpublish.crowdinator_path') . '/versions/' . $version_number . '/config.json';
		if (!is_dir(dirname($config_path)))
			if (!@mkdir(dirname($config_path), 0777, true))
				return array('success' => false, 'message' => sprintf('Could not create directory `%s`.', dirname($config_path)));

		if (!@file_put_contents($config_path, json_encode($conf, JSON_PRETTY_PRINT), LOCK_EX))
			return array(
				'success' => false,
				'message' => sprintf('Can\'t write to `%s`.', $config_path)
			);

		return array('success' => true);
	}

	public static function getList()
	{
		$config = static::getConfig();

		if ($config['success'])
			return array('success' => true, 'data' => array_keys($config['data']['versions']));
		else
			return $config;
	}

	public static function getVersion($version_number)
	{
		$config = static::getConfig();

		if ($config['success'])
		{
			$data = @$config['data']['versions'][$version_number];

			if (!$data)
				return array('success' => false, 'message' => 'Version not found.');

			$vconf = static::getVersionConfig($version_number);

			if (!$vconf['success'])
				return $vconf;

			$data['version_header'] = $vconf['data']['version_header'];

			return array('success' => true, 'data' => $data);
		}
		else
			return $config;
	}

	public static function createOrUpdateVersion($version_number, $data)
	{
		if (!preg_match('/\d+(?:\.\d+)*$/', $version_number))
			return array(
				'success' => false,
				'message' => 'Invalid version number. Must look  like x.x.x.x where the x\'s are integers.'
			);

		$new_entity = !empty($data['new_entity']);

		if (isset($data['archive']))
		{
			$mime = $data['archive']->getMimeType();

			if ($mime !== 'application/zip')
			{
				return array(
					'success' => false,
					'message' => sprintf('Unsupported file of mime type %s, must have mime type application/zip.', $mime)
				);
			}

			$za = new ZipArchive();
			$st = $za->open($data['archive']->getPathname());
			if (true !== $st)
			{
				return array(
					'success' => false,
					'message' => sprintf('Nah. ZipArchive couldn\'t open your file. This is weird. Sorry. ZipArchive said: %d', $st)
				);
			}

			$to_extract = array();
			for ($i = 0; $i < $za->numFiles; $i++)
			{
				$entry = $za->getNameIndex($i);
				if (preg_match('/^\/?prestashop\//', $entry))
					$to_extract[] = $entry;
			}

			$target = Config::get('crowdinpublish.crowdinator_path').'/versions/'.$version_number;

			if (!is_dir($target) && $new_entity)
				if (!@mkdir($target, 0777))
					return array(
						'success' => false,
						'message' => sprintf('Could not create directory `%s`', $target)
					);

			$target = realpath($target);

			if (!$target)
				return array('success' => false, 'message' => 'Could not find the version folder. This is not your fault.');

			if (count($to_extract) < 1000)
				return array('success' => false, 'message' => 'Looks like your archive doesn\'t contain a `prestashop` folder.');

			if (!@$za->extractTo($target, $to_extract))
			{
				return array(
					'success' => false,
					'message' => sprintf('Could not extract archive to `%s`.', $target)
				);
			}

			$shop_dir = $target . '/shop';
			if (is_dir($shop_dir))
			{
				if (!File::deleteDirectory($shop_dir))
					return array('success' => false, 'message' => sprintf('Could not delete directory `%s`.', $shop_dir));
			}

			$prestashop_dir = $target.'/prestashop';

			if (!@rename($prestashop_dir, $shop_dir))
				return array('success' => false, 'message' => sprintf('Could not rename `%1$s` to `%2$s`.', $prestashop_dir, $shop_dir));

			$renamed = static::renameAdminAndInstall($shop_dir);

			if (!$renamed['success'])
				return $renamed;

			$got = static::setupGIT($shop_dir);
			if (!$got['success'])
				return $got;

			$setup = static::setupDirectory($target);
			if (!$setup['success'])
				return $setup;
		}
		else if ($new_entity)
		{
			return array('success' => false, 'message' => 'A ZIP Archive is required when adding a new version.');
		}

		if (isset($data['version_header']))
		{
			if (!is_numeric($data['version_header']) || (int)$data['version_header'] != $data['version_header'])
			{
				return array('success' => false, 'message' => 'Version Header doesn\'t look like an integer.');
			}

			if ($new_entity)
			{
				$conf = array();
			}
			else
			{
				$conf = static::getVersionConfig($version_number);
				if (!$conf['success'])
					return $conf;
				$conf = $conf['data'];
			}

			$conf['version_header'] = $data['version_header'];

			$wrote = static::writeVersionConfig($version_number, $conf);
			if (!$wrote['success'])
				return $wrote;
		}

		if ($new_entity)
		{
			$glob = static::updateGlobalConfigForVersion($version_number);
			if (!$glob['success'])
				return $glob;
		}

		return array('success' => true);
	}

	public static function renameAdminAndInstall($dir)
	{
		foreach (array('admin', 'install') as $name)
		{
			$src = $dir . '/' . $name;
			$dst = $dir . '/' . $name . '-dev';
			if (!is_dir($dst))
			{
				if (!@rename($src, $dst))
					return array('success' => false, 'message' => sprintf('Could not rename `%1$s` to `%2$s`.', $src, $dst));
			} 
		}
		return array('success' => true);
	}

	public static function setupGIT($dir)
	{
		if (!is_dir($dir.'/.git'))
		{
			$cwd = getcwd();
			chdir($dir);
			$unused = array();
			$ret = null;
			@exec('git init .', $unused, $ret);
			chdir($cwd);
			if ($ret !== 0)
				return array('success' => false, sprintf('Could not initialize git in directory `%s`.', $dir));
		}

		chdir($dir);
		// to prevent pull from failing because of permissions
		@exec('git config core.filemode false');
		chdir($cwd);

		return array('success' => true);
	}

	public static function deleteVersion($version_number)
	{
		$conf = static::getConfig();
		if (!$conf['success'])
			return $conf;

		$conf = $conf['data'];
		if (isset($conf['versions'][$version_number]))
			unset($conf['versions'][$version_number]);

		$wrote = static::writeConfig($conf);

		$version_folder = realpath(Config::get('crowdinpublish.crowdinator_path').'/versions/'.$version_number);
		if (is_dir($version_folder))
			if (!File::deleteDirectory($version_folder))
				return array('success' => false, sprintf('Could not delete the `%s` directory.', $version_folder));

		return array('success' => true);
	}

	public static function setupDirectory($dir)
	{
		foreach (array('archive', 'packs') as $folder)
		{
			$path = $dir.'/'.$folder;
			if (!is_dir($path))
				if (!@mkdir($path, 0777))
					return array(
						'success' => false,
						'message' => sprintf('Could not create directory `%s`.', $path)
					);
		}

		$cwd = getcwd();

		chdir($dir);
		$unsued = array();
		$status = null;
		@exec('chmod 777 -R .', $unused, $status);
		chdir($cwd);

		return array('success' => true);
	}
}