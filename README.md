[Constituent Correspondence Data Tool](https://github.com/wvulibraries/ccdt) is a platform to transform congressional constituent correspondence maintained in Intranet Quorum into **information** and **information** into **insights.** The projects aims to provide a holistic interface for importing `flat-files` and provide tools to **research and visualize.**

## Database Setup

After bringing up the project with docker run the setup script from your terminal with the following command to run the database migrations.
 `docker exec -it ccdt_php sh ./scripts/setup.sh`

## PHPUnit

To PHP Unit tests execute the following from your terminal
`docker exec -it ccdt_php vendor/bin/phpunit` 

