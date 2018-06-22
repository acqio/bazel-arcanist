Run you bazel tests using `arc unit` command (also get your BUILD filers formatted using `arc lint`). Here is how:

1. Copy content of this repository into your project:
```
curl -sSL https://github.com/igorgatis/bazel-arcanist/archive/0.1.tar.gz | tar zxf - --strip-components=1 --wildcards bazel-arcanist-0.1/tools/*
```
2. Modify `.arcconfig` to make it look like this:
```
{
  "phabricator.uri": "https://phab.yourserver.com/",
  "load": [
    "tools/arc"
  ],
  "unit.engine": "BazelTestEngine"
}
```
3. Modify `.arclint` to make it look like this:
```
{
  "linters": {
    "buildifier": {
      "type": "buildifier",
      "include": [
        "(^BUILD$)",
        "(\\.BUILD$)",
        "(^WORKSPACE$)"
      ]
    }
  }
}
```
