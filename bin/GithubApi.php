<?php

require_once("Mail.php");

// 引数チェック
$opts = "f:";  // 値が必須
$options = getopt($opts);
if(empty($options)){
	print('Parameter required.' . PHP_EOL);
	print('  ex) $ php ./script.php -f [keyFile]' . PHP_EOL);
	exit(0);
}

// 実行
$githubApi = new GithubApi();

if( $githubApi->getRepos()   &&
    $githubApi->getTeams()   &&
    $githubApi->getMembers() &&
    $githubApi->output()     &&
	$githubApi->alertPublicRepo() ) {
		print('Success.');
		exit(0);
} else {
		print('Error occured.');
		exit(9);
}


class GithubApi {
	// 定数
	const HTTP_BODY  = '0';
	const HTTP_HEAD  = '1';
	const USER_AGENT = 'CY_GithubApi';
	// Publicリポジトリが検出された際のアラート先。カンマ区切り。
	const ALERT_TO   = 'daisuke_senmyou@cybird.co.jp, yuku_suwa@cybird.co.jp, hiroshi_takemoto@cybird.co.jp, katsuhiro_miura@cybird.co.jp, 	kenta_takahashi@cybird.co.jp';
	
	// APIではデフォルト30レコードしか応答しない。
	// per_page パラメーターで100までは増やせるが、どうせ複数回問い合わせなければいけない。
	const URL_REPOS   = 'https://api.github.com/orgs/CYBIRD/repos';
	const URL_TEAMS   = 'https://api.github.com/orgs/CYBIRD/teams';
	const URL_MEMBERS = 'https://api.github.com/teams/%s/members';
	const OUTPUT_REPOS   = '/home/cybird/github/data/repo.tsv';
	const OUTPUT_MEMBERS = '/home/cybird/github/data/member.tsv';

	// 設定値
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
									'--get'        => '',
									'--silent'     => '',
									'--show-error' => '',
								);
		$this->curlOptionHead = array(
									'--user'       => $this->accessKey . ':x-oauth-basic',
									'--user-agent' => self::USER_AGENT,
									'--head'        => '',
									'--silent'     => '',
								);
	}

	// コマンドラインパラメータからアクセスキーのファイルを取得
	function getAccessKey() {
		$opts = "f:";  // 値が必須
		$options = getopt($opts);
		$keyFile = $options['f'];
		// ファイルからアクセスキーを取得
		$fp = @fopen($keyFile, "r");
		if($fp) {
			$accessKey = trim(fgets($fp));
		} else {
			print("Key file [$keyFile] is not found." . PHP_EOL);
			return false;
		}
		// メンバ変数に格納
		if(empty($accessKey)) {
			print("Key file [$keyFile] is empty." . PHP_EOL);
			return false;
		} else {
			$this->accessKey = $accessKey;
		}
		fclose($fp);
		return true;
	}

	// ヘッダーを取得してページ分割されているか確認する。
	function getLink($url) {
		$link = array();
		// "curl --head" でヘッダのみ取得する。
		$curlCmd = $this->getCurlCmd($url, self::HTTP_HEAD);
		exec($curlCmd, $output);
		// へっだ行数分ループ
		for($i = 0; $i < count($output); $i++) {
			// ページ分割されている場合はGitHubAPIからは"Link"ヘッダが応答される。
			if(preg_match('/^Link: .*$/', $output[$i])) {
				$output[$i] = str_replace('Link: ', '', $output[$i]);
				// "," 区切りで複数のリンクURLが記述されている。
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

	// リポジトリ一覧取得
	function getRepos($url = self::URL_REPOS) {

		// 動作環境にPHPのcurlライブラリが使用できないので、execコマンドで利用する。
		$curlCmd = $this->getCurlCmd($url, self::HTTP_BODY);
		$result = exec($curlCmd);

		// 改行削除してjson→配列に変換
		$result = preg_replace("/\r\n|\r|\n/", '', $result);
		$jsonRepos = json_decode($result);
		foreach($jsonRepos as $repo) {
			$this->repos[$repo->name] = array("desc" => $repo->description, "private" => (boolean)$repo->private);
		}
		ksort($this->repos);

		// さっきリクエストしたURLと、Linkヘッダ（rel="last"）のURLが異なっていたら再帰的呼び出し
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

	// チーム一覧取得
	function getTeams($url = self::URL_TEAMS) {

		// 動作環境にPHPのcurlライブラリが使用できないので、execコマンドで利用する。
		$curlCmd = $this->getCurlCmd($url, self::HTTP_BODY);
		$result = exec($curlCmd);

		// 改行削除してjson→配列に変換
		$result = preg_replace("/\r\n|\r|\n/", '', $result);
		// 第2引数にtrueがあれば配列へ、なければオブジェクト。
		$this->teams = array_merge($this->teams, json_decode($result, true));

		// さっきリクエストしたURLと、Linkヘッダ（rel="last"）のURLが異なっていたら再帰的呼び出し
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

	// メンバー一覧取得
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

	// チームごとのメンバー一覧取得
	function getMembersByTeam($url, $team) {
		// 動作環境にPHPのcurlライブラリが使用できないので、execコマンドで利用する。
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

		// さっきリクエストしたURLと、Linkヘッダ（rel="last"）のURLが異なっていたら再帰的呼び出し
		$link = $this->getLink($url);
		if($link !== false && !empty($link['last']) && $url != $link['last']) {
			$this->getMembersByTeam($link['next'], $team);
		}

		return true;
	}

	// Puclicリポジトリがあればアラート発報する。
	function alertPublicRepo() {
		$publicRepos = array();
		foreach($this->repos as $name => $data) {
			if($data['private'] === false) {
				$publicRepos[] = $name;
			}
		}
		
		// メール送信
		if(!empty($publicRepos)) {
			$mailto_array = explode(',', self::ALERT_TO);
			$mail_subject = "GitHubにPublicなリポジトリが作成されました。";
			$mail_body    = "GitHubにPublicなリポジトリが作成されました。" . PHP_EOL;
			foreach($publicRepos as $publicRepo) {
				$mail_body .= "リポジトリ名：$publicRepo" . PHP_EOL;
			}
			$this->mail_send($mailto_array, $mail_subject, $mail_body);
		}
		
		return true;
	}
	
	// メール送信
	function mail_send($mailto_array, $mail_subject, $mail_body) {
		// これを指定しないとmb_encode_mimeheader()で正しくエンコードされない
		mb_language('ja');
		mb_internal_encoding('ISO-2022-JP');
		$params = array(
			//"host" => "mail.sf.cybird.ne.jp",
			"host" => "libml3.sf.cybird.ne.jp",
			"port" => 25,
			"auth" => false,
			"username" => "",
			"password" => ""
		);
		$mailObject = Mail::factory("smtp", $params);
		$headers = array(
			"From" => "noreply@cybird.ne.jp",
			"To" => implode(',', $mailto_array),
			"Subject" => mb_encode_mimeheader(mb_convert_encoding($mail_subject, 'ISO-2022-JP', "SJIS"))
		);
		$mail_body = mb_convert_kana($mail_body, "K", "SJIS");
		$mail_body = mb_convert_encoding($mail_body, "ISO-2022-JP", "SJIS");
		$mailObject->send($mailto_array, $headers, $mail_body);
		// 元に戻す
		mb_internal_encoding('SJIS');
	}

	// ファイル出力
	function output() {
		// リポジトリ取得結果をファイルに出力
		$fp = @fopen(self::OUTPUT_REPOS, "w");
		foreach($this->repos as $name => $data) {
			fwrite($fp, $name . "\t" . $data['desc'] . "\n");
		}
		fclose($fp);

		// メンバー取得結果をファイルに出力
		$fp = @fopen(self::OUTPUT_MEMBERS, "w");
		foreach($this->members as $team => $member) {
			fwrite($fp, $team . "\t" . $member . "\n");
		}
		fclose($fp);

		return true;
	}

	// GETパラメータ付与
	function addGetParam($url, $requestParam) {
		if(strpos($url, '?') === false) {
			$url .= '?';
		}
		foreach($requestParam as $key => $val){
			$url .= urlencode($key) . '=' . urlencode($val) . '&';
		}
		return $url;
	}

	// curlコマンド生成
	function getCurlCmd($url, $target) {
		$cmdOptionStr = '';
		$optionArray = array();
		// 取得対象がヘッダー or ボディ
		if($target === self::HTTP_HEAD) {
			$optionArray = $this->curlOptionHead;
		} elseif($target === self::HTTP_BODY) {
			$optionArray = $this->curlOptionBody;
		}
		// デフォルトのオプション
		foreach($optionArray as $key => $val){
			$cmdOptionStr .= ' ' . escapeshellcmd($key) . ' ' . escapeshellcmd($val);
		}
		return sprintf($this->defaultcurlCmd, $cmdOptionStr, $url);
	}
}
