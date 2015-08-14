<?php
// fgetcsv() 向けの文字コード設定
setlocale(LC_ALL, 'ja_JP.UTF-8');

// cronバッチで出力されたTSVファイルを読み込む
// リポジトリ
$repos = array();
if (($fp = fopen("../data/repo.tsv", "r")) !== false) {
  while(($line = fgetcsv($fp, 1000, "\t")) !== false) {
    $repos[$line[0]] = $line[1];
  }
  fclose($fp);
}

// Team × Member
$members = array();
if (($fp = fopen("../data/member.tsv", "r")) !== false) {
  while(($line = fgetcsv($fp, 0, "\t")) !== false) {
    $members[$line[0]] = $line[1];
  }
  fclose($fp);
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>CYBIRD GitHub Meber</title>
  <link rel="stylesheet" type="text/css" href="./list.css" media="all">
  <link rel="shortcut icon" type="image/vnd.microsoft.icon" href="./favicon.ico" />
</head>
<body>
  <div class="wrap">
    <section>
      <header>
        <h2>CYBIRD GitHub Repository &amp; Team x Member</h2>
      </header>
      <!== リポジトリ ==>
      <article id="repo">
        <h3>Repository</h3>
        <table>
          <tbody>
            <tr>
              <th>Repository</th>
              <th>Description</th>
            </tr>
            <?php foreach($repos as $name => $desc) { ?>
            <tr>
              <td><?php print($name) ?></td>
              <td><?php print($desc) ?></td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </article>
      <!== Team × Member ==>
      <article id="member">
        <h3>Team x Member</h3>
        <table>
          <tbody>
            <tr>
              <th>Team</th>
              <th>Member</th>
            </tr>
            <?php foreach($members as $team => $member) { ?>
            <tr>
              <td><?php print($team) ?></td>
              <td><?php print(str_replace(',', '<br>', $member)) ?></td>
            </tr>
            <?php } ?>
          </tbody>
        </table>
      </article>
    </section>
  </div>
  <footer>
    <small>&copy; <time datetime="2014">2014</time> CYBIRD Co.,Ltd All Rights Reserved.</small>
  </footer>
</body>
</html>
