<?php

// �����`�F�b�N
$opts = "f:";  // �l���K�{
$options = getopt($opts);
if(empty($options)){
	print('Parameter required.' . PHP_EOL);
	print('  ex) $ php ./script.php -f [keyFile]' . PHP_EOL);
	exit(0);
}

// ���s
$githubApi = new GithubApi();
if( $githubApi->getRepos()   &&
    $githubApi->getTeams()   &&
    $githubApi->getMembers() &&
    $githubApi->output()     ) {
		print('Success.');
		exit(0);
} else {
		print('Error occured.');
		exit(9);
}


class GithubApi {
	// �萔
	const HTTP_BODY  = '0';
	const HTTP_HEAD  = '1';
	const USER_AGENT = 'CY_GithubApi';
	// ����DC�ڊǂɔ����s�v�ɂȂ����B
	const PROXY      = 'proxy.sf.cybird.ne.jp:8080';
	// API�ł̓f�t�H���g30���R�[�h�����������Ȃ��B
	// per_page �p�����[�^�[��100�܂ł͑��₹�邪�A�ǂ���������₢���킹�Ȃ���΂����Ȃ��B
	const URL_REPOS   = 'https://api.github.com/orgs/CYBIRD/repos';
	const URL_TEAMS   = 'https://api.github.com/orgs/CYBIRD/teams';
	const URL_MEMBERS = 'https://api.github.com/teams/%s/members';
	const OUTPUT_REPOS   = '/home/cybird/github/data/repo.tsv';
	const OUTPUT_MEMBERS = '/home/cybird/github/data/member.tsv';

	// �ݒ�l
	private $defaultcurlCmd;
	private $repos;
	private $teams;
	private $members;
	private $accessKey;
	private $curlOptionBody;
	private $curlOptionHead;

	function GithubApi() {
		$this->defaultcurlCmd = 'curl %s \'%s\'';
		$this->repos          = array();
		$this->teams          = array();
		$this->members        = array();

		$this->getAccessKey();
		$this->curlOptionBody = array(
									'--user'       => $this->accessKey . ':x-oauth-basic',
									'--user-agent' => self::USER_AGENT,
									//'--proxy'      => self::PROXY,
									'--get'        => '',
									'--silent'     => '',
									'--show-error' => '',
								);
		$this->curlOptionHead = array(
									'--user'       => $this->accessKey . ':x-oauth-basic',
									'--user-agent' => self::USER_AGENT,
									//'--proxy'      => self::PROXY,
									'--head'        => '',
									'--silent'     => '',
								);
	}

	// �R�}���h���C���p�����[�^����A�N�Z�X�L�[�̃t�@�C�����擾
	function getAccessKey() {
		$opts = "f:";  // �l���K�{
		$options = getopt($opts);
		$keyFile = $options['f'];
		// �t�@�C������A�N�Z�X�L�[���擾
		$fp = @fopen($keyFile, "r");
		if($fp) {
			$accessKey = trim(fgets($fp));
		} else {
			print("Key file [$keyFile] is not found." . PHP_EOL);
			return false;
		}
		// �����o�ϐ��Ɋi�[
		if(empty($accessKey)) {
			print("Key file [$keyFile] is empty." . PHP_EOL);
			return false;
		} else {
			$this->accessKey = $accessKey;
		}
		fclose($fp);
		return true;
	}

	// �w�b�_�[���擾���ăy�[�W��������Ă��邩�m�F����B
	function getLink($url) {
		$link = array();
		// "curl --head" �Ńw�b�_�̂ݎ擾����B
		$curlCmd = $this->getCurlCmd($url, self::HTTP_HEAD);
		exec($curlCmd, $output);
		// �ւ����s�������[�v
		for($i = 0; $i < count($output); $i++) {
			// �y�[�W��������Ă���ꍇ��GitHubAPI�����"Link"�w�b�_�����������B
			if(preg_match('/^Link: .*$/', $output[$i])) {
				$output[$i] = str_replace('Link: ', '', $output[$i]);
				// "," ��؂�ŕ����̃����NURL���L�q����Ă���B
				$linkHeaders = explode(",", $output[$i]);
				foreach($linkHeaders as $linkHeader) {
					$linkHeader = trim($linkHeader);
					// rel="first|next|prev|last"
					if(preg_match('/^<.*>; rel="(.*)"$/', $linkHeader, $matches)) {
						$linkRel = $matches[1];
						if(preg_match('/^<(.*)>;.*$/', $linkHeader, $matches)) {
							$link[$linkRel] = $matches[1];
						}
					}
				}
			}
		}
		if(empty($link)) {
			return false;
		} else {
			return $link;
		}
	}

	// ���|�W�g���ꗗ�擾
	function getRepos($url = self::URL_REPOS) {

		// �������PHP��curl���C�u�������g�p�ł��Ȃ��̂ŁAexec�R�}���h�ŗ��p����B
		$curlCmd = $this->getCurlCmd($url, self::HTTP_BODY);
		$result = exec($curlCmd);

		// ���s�폜����json���z��ɕϊ�
		$result = preg_replace("/\r\n|\r|\n/", '', $result);
		$jsonRepos = json_decode($result);
		foreach($jsonRepos as $repo) {
			$this->repos[$repo->name] = $repo->description;
		}
		ksort($this->repos);

		// ���������N�G�X�g����URL�ƁALink�w�b�_�irel="last"�j��URL���قȂ��Ă�����ċA�I�Ăяo��
		$link = $this->getLink($url);
		if($link !== false && !empty($link['last']) && $url != $link['last']) {
			$this->getRepos($link['next']);
		}

		if(empty($this->repos)) {
			print("Failure getRepos().\n");
			return false;
		} else {
			return true;
		}
	}

	// �`�[���ꗗ�擾
	function getTeams($url = self::URL_TEAMS) {

		// �������PHP��curl���C�u�������g�p�ł��Ȃ��̂ŁAexec�R�}���h�ŗ��p����B
		$curlCmd = $this->getCurlCmd($url, self::HTTP_BODY);
		$result = exec($curlCmd);

		// ���s�폜����json���z��ɕϊ�
		$result = preg_replace("/\r\n|\r|\n/", '', $result);
		// ��2������true������Δz��ցA�Ȃ���΃I�u�W�F�N�g�B
		$this->teams = array_merge($this->teams, json_decode($result, true));

		// ���������N�G�X�g����URL�ƁALink�w�b�_�irel="last"�j��URL���قȂ��Ă�����ċA�I�Ăяo��
		$link = $this->getLink($url);
		if($link !== false && !empty($link['last']) && $url != $link['last']) {
			$this->getTeams($link['next']);
		}

		if(empty($this->teams)) {
			print("Failure getTeams().\n");
			return false;
		} else {
			return true;
		}
	}

	// �����o�[�ꗗ�擾
	function getMembers() {
		foreach($this->teams as $team) {
			$urlGetMembersByTeam = sprintf($url = self::URL_MEMBERS, $team['id']);
			$this->getMembersByTeam($urlGetMembersByTeam, $team);
		}

		if(empty($this->members)) {
			print("Failure getMembers().\n");
			return false;
		} else {
			return true;
		}
	}

	// �`�[�����Ƃ̃����o�[�ꗗ�擾
	function getMembersByTeam($url, $team) {
		// �������PHP��curl���C�u�������g�p�ł��Ȃ��̂ŁAexec�R�}���h�ŗ��p����B
		$curlCmd = $this->getCurlCmd($url, self::HTTP_BODY);
		$result = exec($curlCmd);
		$result = preg_replace("/\r\n|\r|\n/", '', $result);
		$jsonMembers = json_decode($result);
		foreach($jsonMembers as $member) {
			if(array_key_exists($team['name'], $this->members)) {
				$this->members[$team['name']] = $this->members[$team['name']] . ',' . $member->login;
			} else {
				$this->members[$team['name']] = $member->login;
			}
		}

		// ���������N�G�X�g����URL�ƁALink�w�b�_�irel="last"�j��URL���قȂ��Ă�����ċA�I�Ăяo��
		$link = $this->getLink($url);
		if($link !== false && !empty($link['last']) && $url != $link['last']) {
			$this->getMembersByTeam($link['next'], $team);
		}

		return true;
	}

	// �t�@�C���o��
	function output() {
		// ���|�W�g���擾���ʂ��t�@�C���ɏo��
		$fp = @fopen(self::OUTPUT_REPOS, "w");
		foreach($this->repos as $name => $desc) {
			fwrite($fp, $name . "\t" . $desc . "\n");
		}
		fclose($fp);

		// �����o�[�擾���ʂ��t�@�C���ɏo��
		$fp = @fopen(self::OUTPUT_MEMBERS, "w");
		foreach($this->members as $team => $member) {
			fwrite($fp, $team . "\t" . $member . "\n");
		}
		fclose($fp);

		return true;
	}

	// GET�p�����[�^�t�^
	function addGetParam($url, $requestParam) {
		if(strpos($url, '?') === false) {
			$url .= '?';
		}
		foreach($requestParam as $key => $val){
			$url .= urlencode($key) . '=' . urlencode($val) . '&';
		}
		return $url;
	}

	// curl�R�}���h����
	function getCurlCmd($url, $target) {
		$cmdOptionStr = '';
		$optionArray = array();
		// �擾�Ώۂ��w�b�_�[ or �{�f�B
		if($target === self::HTTP_HEAD) {
			$optionArray = $this->curlOptionHead;
		} elseif($target === self::HTTP_BODY) {
			$optionArray = $this->curlOptionBody;
		}
		// �f�t�H���g�̃I�v�V����
		foreach($optionArray as $key => $val){
			$cmdOptionStr .= ' ' . escapeshellcmd($key) . ' ' . escapeshellcmd($val);
		}
		return sprintf($this->defaultcurlCmd, $cmdOptionStr, $url);
	}
}
