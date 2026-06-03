Feature: UI5 Table test

  Scenario: Open given page
    Given I log in to the page "nbr.onelink.bestellprozess-maststahl-materialbedarfsliste.html"
    Then I see 1 widget of type "DataTable"