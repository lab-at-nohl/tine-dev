*Get development going, fast. Containers for development of Tine Groupware. Rootless, operated by regular users (root is only needed to make the hostname known). Requires **podman** and **podman-compose**. Setup < 30 minutes.* 

# tine-dev Setup in userspace with podman

**Audience: community developers running linux** - *Tine Groupware* provides docker-based containers for a complete development setup, including relevant backends like DB, cache and a mailstack for testing (great!). As some images live in a non-public repository (as of June 2025), this fork adjusted the original [tine-dev repository](https://github.com/tine-groupware/tine-dev) to **use public sources**. Further, with docker you have to operate containers as root which might break thinks on your development server. Despite this fork utilizes *podman* (similar to docker) with advanced networking options and the ability to run **in user space**. 

## Overview/Caveats

User's containers cannot open ports below 1024. Thus, you may find your development environment at **ports 8443 (ssl), 10443 (webpack/ssl) and 8080 (http, usually not needed). See [https://tine.local.tine-dev.de:8443]() eventually. 

Podman can combine containers into a single environment (e.g. network stack) called **Pod**. Therefore the network bridges *internal_network* and *external_network* are not required. Podman provides **pods** instead. Caveat: All ports inside the pod have to be unique (see below).   

The user require **about 3.5 GB free space**. With podman you can find all rootless containers, images and so on at `~/.local/share/containers/`. 


## Prepare stack and podman

The way below is tested but may differ from the original instructions. All instructions are run by **non-root** user: 

### Clone repository

As of June 2025 you have to have `git`, `PHP 8.3` and `composer` installed locally. 

```sh
git clone -b podman-rootless --single-branch https://github.com/lab-at-nohl/tine-dev.git tine-dev-podman-rootless/
cd tine-dev-podman-rootless/
composer install
```

### Setup podman

```sh
systemctl --user start podman
ls -l /run/user/$UID/podman/podman.sock
# srw-rw---- 1 user1 users 0 28. Mai 12:37 /run/user/1000/podman/podman.sock
```

Please note, the UID is 1000 here (usually the first regular user). If you use a different UID you have to edit [docker-compose.yml]() accordingly, see below.  

### Add local domain

Unfortunately at this point you need to be root to edit `/etc/hosts`. Otherwise you have to setup/edit a DNS to include `tine.local.tine-dev.de`. Edit the hosts-file to add *tine.local.tine-dev.de* next to localhost like below:

```
#    
# IP-Address  Full-Qualified-Hostname  Short-Hostname(s)
#

127.0.0.1	localhost tine.local.tine-dev.de
```

Please note, with rootless podman containers tine-dev lives - from outside view - on localhost (while the original docker setup brings its own subnet 172.118.0.1/16). 


## Run tine-dev stack

### Initial run, setup/install

The mailstack images are made by the tine developers, node is an enhancement of the official image. Unfortunately their repository is internal only, thus you have to build them yourself locally. This is fast and easy and it does not require much space:

```sh
podman build --tag postfix:1.0.5 dockerfiles/mailstack/postfix/
podman build --tag dovecot:1.0.3 dockerfiles/mailstack/dovecot/
podman build --tag mailstackcontrol:1.0.5 dockerfiles/mailstack/control/
podman build --tag node:18.9.0-alpine dockerfiles/node/2023.11/
```

Other containers can be downloaded/pulled (automatically during setup if configured, see below):

- registry.hub.docker.com/tinegroupware/dev:main-8.3
- docker.io/library/traefik:v3.3
- docker.io/library/mariadb:10.9.8
- docker.io/library/redis:6.0.16
- docker.io/dockage/mailcatcher:0.9.0
- docker.io/clamav/clamav:latest

Please note: To avoid downloading the full *node:18.9*-Image (1.1GB large), which is only needed to run 'npm install' respectively `./console src:npmInstall` *once*, you nedd the modified node:18.9.0-alpine (30 MB extra). If you have trouble, however, try to use the full image (see at the end of this document); you may to remove it afterwards by issuing `podman image rm docker.io/library/node:18.9`. 

Now bring the necessary containers up. As the following console won't dettach, just open another one afterwards. 

```sh
systemctl --user start podman
podman pod create --security-opt apparmor=unconfined tine20 
./console docker:up
```

Please note the parameter `--security-opt apparmor=unconfined`. This turns apparmor off, otherwise it will catch your php-fpm and other services. Similar parameters exist for SELinux. If you don't use either, go without this. 

The `docker:up`-command should have provided for `cd tine20 && git submodule init && git submodule update && cd ..`. If you encounter problems with *initialize icon-set submodule*, missing icons or similar run that command manually inside the folder `tine20/tine20/`. 

Next you have to install Tine Groupware in its containers (open another cli - the console before will continue to print out messages from the containers; Or press Ctrl+C to detach): 

```sh
# Patch webpack to find the web-container inside the pod
sed -e 's|http://localhost/|http://tine20_web_1/|' -i tine20/tine20/Tinebase/js/webpack.dev.js
# For unknown reason not all containers come up at first run...
podman pod restart tine20
podman ps -a # << just to make sure >>
# ... STATUS ... NAMES
#     Up ...     38302809a192-infra
#     Up ...     traefik
#     Up ...     tine20_cache_1
#     Up ...     tine20_db_1
#     Exited (0) tine20_mailstack_1
#     Up ...     tine20_mailcatcher_1
#     Up ...     clamav
#     Up ...     tine20_postfix_1
#     Up ...     tine20_dovecot_1
#     Up ...     tine20_web_1
#     Up ...     tine20_webpack_1
#
# prepare source code, install npm (consider freeing space if full node:18.9.0 is used, see above)
./console src:composer install
./console src:npmInstall
# Generate self-signed cert and copy CA to host to import into browser 
./console docker:generateCert
podman cp traefik:/etc/traefik/ca.pem ./
# install Tine groupware <> setup.php --install; For unknown reasons need to be run twice
./console tine:install
./console tine:install
```

If `tine:install` fails, just run it a second time (it was the case to me). Also it might be preferable to setup xDebug before running; Careful, tine-dev uses **port 9001** (default: 9003). If you use VS Code, you can find a short tutorial in [step 1 here](https://thomashysselinckx.medium.com/activating-xdebug-on-visual-studio-code-laravel-herd-cfd0553d26e0), following this the error message regarding xDebug will disappear. 

Visit https://tine.local.tine-dev.de:8443, login as tine20admin pw: tine20admin

### Reuse stack later - start stop pod

You can administrate your stack like this (again: as a regular user):

```sh
# Create the podman.sock if not yet there
systemctl --user start podman
podman pod start tine20
podman pod stop tine20
podman pod restart tine20
```

The `systemctl` call is required only if after you reboot the computer. It is harmless to do so twice though. 

Visit https://tine.local.tine-dev.de:8443, login as tine20admin pw: tine20admin

### Remove stack

To remove tine-dev (not the downloaded/built images) run:

```sh
podman pod stop tine20
podman pod rm tine20
```

The following may help (if anything brakes) and your user has **only tine-dev containers** (as it will remove everything from userspace), including the images.  

```sh
podman stop -a
podman rm -a
podman image prune -a
podman network prune
```

In case of a total hang, you can issue `podman system prune`. 

## Setup additional containers

to do.

## Modifications and further reading

Below you can find the ratio of modifications if you need to adjust anything. This branch in the tine-dev **fork comes with the necessary changes already**, see in detail below. 

### Changes regarding networking

While the docker compose files come with sophisticated networking, *internal_network* and *external_network*, podman **pods** provide a network in which all containers share the same ip to the outside but have, however, different hostnames inside. Thus, internal/external extra networks are not needed but **ports have to be unique** (even though not all are exposed to the outside) and **exposed ports cannot be below 1024**; Additionally *localhost* only adresses the same container not the whole pod. 

1. **Webpack** cannot connect to contianer web by *localhost*, thus change it to the container's name: `sed -e 's|http://localhost/|http://tine20_web_1/|' -i tine20/tine20/Tinebase/js/webpack.dev.js` (documentation here only; run after tine source is downloaded by script)

2. Remove networking in **all compose files**, be specific for intra-container communication: `sed -e 's/  networks:$/  #networks:/' -e 's/  - internal_network/#  - internal_network/' -e 's/  - external_network/#  - external_network/' -e 's/  - sentry-net/#  - sentry-net/' -i compose/*.yml`

3. **Manually comment network-entries** in `docker-compose.yml`, like before but manually - don't forget the network eentries at the end. 

4. Adjust *exposed/internal ports* for **Traefik** in `docker-compose.yml` like this (8080 = 80, 8443 = 443, 18443 = 10443) and *mount the socket by UID*:
```yml
    command:
      - --providers.docker=true
      - --providers.docker.exposedbydefault=false
      - --providers.file.directory=/etc/traefik/dynamic
      - --entrypoints.web.address=:8080
      - --entrypoints.web.http.redirections.entryPoint.to=websecure
      - --entrypoints.web.http.redirections.entryPoint.scheme=https
      - --entrypoints.web.http.redirections.entrypoint.permanent=true
      - --entrypoints.websecure.address=:8443
      - --entrypoints.webpacksecure.address=:18443
      - --accesslog=true
      - --api=true
    ports:
      - 8080:8080
      - 8443:8443
      - 10443:18443
    volumes:
      - ./configs/traefik/:/etc/traefik/:ro
      - /run/user/1000/podman/podman.sock:/var/run/docker.sock:ro
```

5. Change for image **web** in `docker-compose.yml` the server URL like `TINE20_URL: https://tine.local.tine-dev.de:8443`. 

### compose/mailstack.yml

Change compose-file to use the images made by `podman build` (see above) 

```yml
services:
  postfix:
    #image: dockerregistry.metaways.net/tine20/docker/postfix:1.0.5
    image: postfix:1.0.5

  dovecot:
    #image: dockerregistry.metaways.net/tine20/docker/dovecot:1.0.3
    image: dovecot:1.0.3

mailstack:
    #image: dockerregistry.metaways.net/tine20/docker/mailstackcontrol:1.0.5
    image: mailstackcontrol:1.0.5
```

### cli/Commands/Docker/DockerCommand.php

To use podman modify: 

```php
    #protected array $composeCommand = ['docker', 'compose'];
    protected array $composeCommand = ['podman', 'compose', '--podman-run-args="--pod=tine20"'];`
```

And link to publicly available images:

```php
        'main' => [
        #    'web' => 'dockerregistry.metaways.net/tine20/tine20/dev:2024.11-8.3',
        #    'webpack' => 'dockerregistry.metaways.net/tine20/tine20/node:18.9.0-alpine-r1',
            'web' => 'registry.hub.docker.com/tinegroupware/dev:main-8.3',
            'webpack' => 'localhost/18.9.0-alpine',
```

### cli/Commands/Src/NpmCommand.php

Inside function `protected function execute(InputInterface $input, OutputInterface $output)`: 

```php
        #passthru("docker run --rm \
        #    --user " . trim(`id -u`) . ':' . trim(`id -g`) . " \
        #    -v $localCacheDir:/.npm \
        #    -v {$this->getTineDir($io)}/Tinebase/js:/usr/share/tine20/Tinebase/js \
        #    {$env['WEBPACK_IMAGE']} \
        #    sh -c 'cd /usr/share/tine20/Tinebase/js && npm {$input->getArgument('cmd')}'", $result_code); // --loglevel verbose
        passthru("podman run --rm \
            -v $localCacheDir:/.npm \
            -v {$this->getTineDir($io)}/Tinebase/js:/usr/share/tine20/Tinebase/js \
            {$env['WEBPACK_IMAGE']} \
            sh -c 'cd /usr/share/tine20/Tinebase/js && npm {$input->getArgument('cmd')}'", $result_code);

```

### cli/Commands/Src/NpmInstallCommand.php

Inside function `public function runNpmInstall($dir): int`:

```php
        #passthru("docker run --rm \
        #    --user " . trim(`id -u`) . ':' . trim(`id -g`) . " \
        #    -v $localCacheDir:/.npm \
        #    -v $dir:/usr/share/tine20/Tinebase/js \
        #    {$env['WEBPACK_IMAGE']} \
        #    sh -c 'cd /usr/share/tine20/Tinebase/js && npm prune --no-optional --ignore-scripts'", $result_code); // --loglevel verbose
        passthru("podman run --rm \
            -v $localCacheDir:/.npm \
            -v $dir:/usr/share/tine20/Tinebase/js \
            {$env['WEBPACK_IMAGE']} \
            sh -c 'cd /usr/share/tine20/Tinebase/js && npm prune --no-optional --ignore-scripts'", $result_code);
```

### cli/Commands/Src/ComposerCommand.php

Inside function `publiprotected function execute(InputInterface $input, OutputInterface $output)`:

```php
        #passthru('docker run --rm --user ' . trim(`id -u`) . ':' . trim(`id -g`) .
        #    ' -v ' . $tineDir . ':/usr/share/tine20' .
        #    ' -v ' . $tineDir . '/../tests:/usr/share/tests' .
        #    ' -v ' . $this->baseDir . '/data/composer:/.composer' .
        #    ' -v ' . $localCacheDir . ':/composercache' .
        #    ' '. $env['WEB_IMAGE'] . ' sh -c "cd /usr/share/tine20; composer config --global cache-dir /composercache; composer ' . $input->getArgument('cmd') . '"', $result_code);
        passthru('podman run --rm ' . 
            ' -v ' . $tineDir . ':/usr/share/tine20' .
            ' -v ' . $tineDir . '/../tests:/usr/share/tests' .
            ' -v ' . $this->baseDir . '/data/composer:/.composer' .
            ' -v ' . $localCacheDir . ':/composercache' .
            ' '. $env['WEB_IMAGE'] . ' sh -c "cd /usr/share/tine20; composer config --global cache-dir /composercache; composer ' . $input->getArgument('cmd') . '"', $result_code);
```

### configs/xdebug/xdebug.ini

`xdebug.client_host=host.containers.internal`

### compose/xdebug.yml

`XDEBUG_CONFIG: "remote_host=host.containers.internal remote_enable=on remote_port=9001"`

### compose/broadcasthub.yml

```yml
    #image: dockerregistry.metaways.net/tine20/tine20-broadcasthub:0.8-r2
    image: registry.hub.docker.com/tinegroupware/broadcasthub:latest

      TINE20_JSON_API_URL: https://tine20_web_1
      TINE20_JSON_API_URL_PATTERN: (https://tine20_web_1)|(http://tenant(1|2|3).my-domain.test)
```
