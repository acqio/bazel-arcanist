<?php
// Heavily inspired by:
// https://github.com/google/kythe/blob/master/tools/arc/unit/engine/BazelTestEngine.php
// With proper support for event file.
final class BazelTestEngine extends ArcanistUnitTestEngine {
  private static $omit_tags = ["manual", "broken", "arc-ignore", "docker"];
  private $debug;
  private $useConfig;
  private $waitForBazel;

  private $project_root;
  private $testlogs_root;

  protected function supportsRunAllTests() {
    return true;
  }

  public function run() {
    if (getenv("DEBUG")) {
      $this->debug = true;
    }
    $this->debugPrint("run");
    $this->useConfig = true;
    if (getenv("WAIT_FOR_BAZEL")) {
      $this->waitForBazel = true;
    }

    $this->project_root = $this->getWorkingCopy()->getProjectRoot();
    $this->testlogs_root = $this->bazelInfo("bazel-testlogs");

    $targets = $this->getTargets();
    if (empty($targets)) {
      return array();
    }
    return $this->runTests($targets);
  }

  private function getTargets() {
    $this->debugPrint("getTargets()");

    if ($this->getRunAllTests()) {
      return array("//...");
    }

    $files = $this->getFileTargets();
    if (empty($files)) {
      print("No files affected\n");
      return array();
    }
    // Quote each file to make it safe in case it has special characters in it.
    $files = array_map(function($s) { return '"'.$s.'"'; }, $files);
    $files = join($files, " ");

    $cmd = $this->bazelCommand("query", ["-k", "%s"]);
    $tag_filter = join("|", self::$omit_tags);
    $query = 'rdeps(//..., set('.$files.')) intersect tests(//...) except attr(tags, "'.$tag_filter.'", //...)';

    $future = new ExecFuture($cmd, $query);
    $this->debugPrint($future->getCommand());
    $future->setCWD($this->project_root);

    print("Querying affected files...\n");
    $status = $future->resolve();
    if ($status[0] != 3 && $status[0] != 0) {
      throw new Exception("Bazel query error (".$status[0]."): ".$status[2]);
    }
    $output = trim($status[1]);
    if ($output === "") {
      print("No targets affected\n");
      return array();
    }
    // Quote each target to make it safe in case it has special characters in it.
    return array_map(function($s) { return '"'.$s.'"'; }, explode("\n", $output));
  }

  private function getFileTargets() {
    $this->debugPrint("getFileTargets()");

    if (empty($this->getPaths())) {
      return array();
    }
    $files = join(
      " ", array_map(array('BazelTestEngine', 'fileToTarget'), $this->getPaths()));

    $future = new ExecFuture($this->bazelCommand("query", ["-k", "%s"]), 'set('.$files.')');
    $this->debugPrint($future->getCommand());
    $future->setCWD($this->project_root);

    print("Discovering affected test targets...\n");
    $status = $future->resolve();
    if ($status[0] != 3 && $status[0] != 0) {
      throw new Exception("Bazel query error (".$status[0]."): ".$status[2]);
    }

    $output = trim($status[1]);
    if ($output === "") {
      return array();
    }

    return explode("\n", $output);
  }

  private static function fileToTarget($file) {
    if (dirname($file) == ".") {
      return '//:' . $file;
    }
    return "\"" . $file . "\"";
  }

  private function runTests($targets) {
    $this->debugPrint("runTests()");

    if (!file_exists($this->testlogs_root)) {
      mkdir($this->testlogs_root, 0777, true);
    }
    $events_file = $this->testlogs_root . "/events.json";
    if (file_exists($events_file)) {
      unlink($events_file);
    }

    $tag_filters = join(",", array_map(function($s) { return "-$s"; }, self::$omit_tags));
    $future = new ExecFuture($this->bazelCommand("test", array_merge([
        "--verbose_failures",
        "--test_tag_filters=$tag_filters",
        "--show_progress_rate_limit=0.3",
        "--color=yes",
        "--curses=yes",
        "--symlink_prefix=/",
        "--build_event_json_file=$events_file",
        ""],
        $targets)));
    $this->debugPrint($future->getCommand());
    $future->setCWD($this->project_root);

    $future->start();
    do {
      usleep(200*1000);
      list($stdout, $stderr) = $future->read();
      print($stderr);
      $future->discardBuffers();
    } while (!$future->isReady());

    $status = $future->resolve();
    $code = $status[0];
    if ($code == 4) {
      print("No tests affected...\n");
      return [];
    } else if ($code == 1) {
      throw new Exception($output . "\n" . $status[2]);
    }

    return $this->parseEventFile($targets, $events_file);
  }

  private function parseEventFile($targets, $events_file) {
    $this->debugPrint("parseEventFile()");

    $results = array();
    $file = fopen($events_file,"r");
    while (!feof($file)){
      $line = fgets($file);
      if (strpos($line, 'testResult') !== false) {
        $obj = json_decode($line, true);
        $result = $this->parseResultEvent($targets, $obj);
        if ($result !== null) {
          $results[] = $result;
        }
      }
    }

    usort($results, array('BazelTestEngine', "orderTestResultByName"));
    return $results;
  }

  private static function orderTestResultByName($a, $b) {
    return strcmp($a->getName(), $b->getName());
  }

  private function parseResultEvent($targets, $obj) {
    if (!array_key_exists("id", $obj) ||
        !array_key_exists("testResult", $obj)) {
      return null;
    }

    $node = $obj["testResult"];
    $result = new ArcanistUnitTestResult();
    try {
      $result->setName($obj["id"]["testResult"]["label"]);

      if (array_key_exists("testAttemptDurationMillis", $node)) {
        $result->setDuration(intval($node["testAttemptDurationMillis"]) / 1000);
      }

      // Useful links regarding status mapping between bazel and arcanist:
      //
      // https://github.com/phacility/arcanist/blob/master/src/unit/ArcanistUnitTestResult.php#L208
      //
      // https://github.com/bazelbuild/bazel/blob/master/src/main/java/com/google
      //   /devtools/build/lib/buildeventstream/proto/build_event_stream.proto#L489
      //
      // https://github.com/bazelbuild/intellij/blob/master/base/src/com/google
      //   /idea/blaze/base/run/smrunner/BlazeXmlToTestEventsConverter.java#L180
      switch ($node["status"]) {
        case "PASSED":
          $result->setResult(ArcanistUnitTestResult::RESULT_PASS);
          break;
        case "FAILED":
        case "REMOTE_FAILURE":
        case "TIMEOUT":
          $result->setResult(ArcanistUnitTestResult::RESULT_FAIL);
          break;
        case "NO_STATUS":
        case "FLAKY":
        case "INCOMPLETE":
        case "FAILED_TO_BUILD":
        case "TOOL_HALTED_BEFORE_TESTING":
        default:
          $result->setResult(ArcanistUnitTestResult::RESULT_BROKEN);
          break;
      }
    } catch (CommandException $exc) {
      $this->debugPrint($exc);
      $result->setResult(ArcanistUnitTestResult::RESULT_BROKEN);
    }
    return $result;
  }

  private function bazelInfo($name) {
    $future = new ExecFuture($this->bazelCommand("info", ["--fetch", $name]));
    $this->debugPrint($future->getCommand());
    $future->setCWD($this->project_root);
    $future->resolve();
    list($stdout) = $future->read();
    return trim($stdout);
  }

  private function bazelCommand($subcommand, $args) {
    $cmd = "bazel ";
    if (!$this->useConfig) {
      $cmd = $cmd . "--bazelrc=/dev/null ";
    }
    if (!$this->waitForBazel) {
      $cmd = $cmd . "--noblock_for_lock ";
    }
    $cmd = $cmd . $subcommand . " --tool_tag=arcanist ";
    $cmd = $cmd . join(" ", $args);
    return $cmd;
  }

  private function debugPrint($msg) {
    if ($this->debug) {
      print("DEBUG: " . $msg . "\n");
    }
  }
}
