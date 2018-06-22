Run you bazel tests using `arc unit` command (also get your BUILD filers formatted using `arc lint`). Here is how:

1. Copy content of this repository into your project.
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
3. Modify `.arcconfig` to make it look like this:
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

