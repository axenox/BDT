Feature: Table test

  Scenario: Open given page
    Given I log in to the page "my.app.bestellprozess-maststahl-materialbedarfsliste.html"
    Then I see 1 widget of type DataTable