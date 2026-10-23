IQViewer is a fork of West Virginia's [Constituent Correspondence Data Tool](https://github.com/wvulibraries/ccdt), developed to transform **congressional data** into **information** and **information** into **insights.** The projects aims to provide a holistic interface for importing `flat-files` and provide tools to **research and visualize.**

## Quick Start

 [WVU Presentation](https://docs.google.com/presentation/d/1FyzvqbXJCVkH5S1Af2u6OOTHvzqgfDztlYbq4fLybr4/edit?usp=sharing) | [WVU Documentation](https://github.com/wvulibraries/ccdt/wiki)
------------ | -------------
 [![Presentation](docs/images/i2.png)](https://docs.google.com/presentation/d/1FyzvqbXJCVkH5S1Af2u6OOTHvzqgfDztlYbq4fLybr4/edit#slide=id.g35f391192_00/edit?usp=sharing) | [![Documentation](docs/images/i1.png)](https://github.com/wvulibraries/ccdt/wiki)

## Development and Contributing

## Database Setup

After bringing up the project with docker run the setup script from your terminal with the following command to run the database migrations.
 `docker exec -it ccdt_php sh ./scripts/setup.sh`

## Chart Builder

Signed-in users can draw charts of imported tables (matplotlib), including charts that combine two tables of a collection. See [docs/charts.md](docs/charts.md) for setup.

## PHPUnit

To PHP Unit tests execute the following from your terminal
`docker exec -it ccdt_php vendor/bin/phpunit` 
