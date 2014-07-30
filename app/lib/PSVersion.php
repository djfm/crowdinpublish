<?php

class PSVersion
{
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

		$config_path = Config::get('crowdinpublish.crowdinator_path') . '/config.json';
		if (!@file_put_contents($config_path, json_encode($config, JSON_PRETTY_PRINT), LOCK_EX))
		{
			return array('success' => false, 'message' => sprintf('Could not write config to `%s`.', $config_path));
		}

		return array('success' => true);
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
			if (!@mkdir($config_path, 0777, true))
				return array('success' => false, 'message' => sprintf('Could not create directory `%s`.', dirname($config_path)));

		file_put_contents($config_path, json_encode($conf, JSON_PRETTY_PRINT), LOCK_EX);

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
		$glob = static::updateGlobalConfigForVersion($version_number);
		if (!$glob['success'])
			return $glob;

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

			$target = realpath(Config::get('crowdinpublish.crowdinator_path').'/versions/'.$version_number);

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

			$got = static::initEmptyGitRepoIfNeeded($shop_dir);
			if (!$got['success'])
				return $got;

			if (isset($data['version_header']))
			{
				if (!is_numeric($data['version_header']) || (int)$data['version_header'] != $data['version_header'])
				{
					return array('success' => false, 'message' => 'Version Header doesn\'t look like an integer.');
				}

				$conf = static::getVersionConfig($version_number);
				if (!$conf['success'])
					return $conf;

				$conf = $conf['data'];
				$conf['version_header'] = $data['version_header'];

				$wrote = static::writeVersionConfig($version_number, $conf);
				if (!$wrote['success'])
					return $wrote;
			}

			return array('success' => true);
		}

		return;
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

	public static function initEmptyGitRepoIfNeeded($dir)
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

		return array('success' => true);
	}
}