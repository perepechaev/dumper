# PHP dumper
Tools for dumping and debugging PHP code

## Some useful function 
`d` - dump any object or variables 
```d($var1, $var2);```

`dd` - dump and day any variables
```
d($var1, $var2...);
```

`dt` - dump tracert
```
dt();
```

`dc` - dump class name of variable
```
dc($obj);
```

`df` - dump to file. First argument - filename
```
dc('/tmp/dump.log', $var1, $var2...);
```

