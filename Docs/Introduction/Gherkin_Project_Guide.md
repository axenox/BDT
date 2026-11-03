# Gherkin Guide for the Project

## 1. Purpose and scope

This document describes how Gherkin scenarios are structured in the project and how the existing project-specific steps are used. It focuses on:

- Scenario structure and step order
- Login, roles, and locale
- Loading prepared test data
- Selecting pages, tables, and widgets
- Accessing data through filters
- Checking tables, columns, buttons, tabs, and tiles
- Editing forms and DataSpreadsheets
- Smoke, authorization, and export tests

---

## 2. Basic structure of a Gherkin scenario

A scenario normally follows this order:

1. **Given**: Establish the initial state, for example by logging in and loading test data.
2. **When**: Perform an action or select the working context.
3. **Then**: Check the expected result.
4. **And**: Add further steps to the same logical group.

```gherkin
Feature: Order management

  Scenario: Filter orders by city
    Given I log in to the page "my.app.orders.html" as "Support" with locale "de_DE"
    When I look at table 1
    And I enter "Berlin" in filter "City"
    And I click button "Suchen"
    Then I see "Berlin" in column "City"
```

### Project rule for step order

The order of steps should be clear from both a business and technical perspective:

```text
Login -> focus a page/widget -> find test data -> perform an action -> check the result
```

A step that acts on a table or widget must only be used after the relevant context has been focused.

---

## 3. Login, role, and locale

### 3.1 Log in to a page

```gherkin
Given I log in to the page "exface.core.logs.html"
```

Specify the page by its alias. Depending on the step, the `.html` suffix may be required or expected.

### 3.2 Log in with a role

```gherkin
Given I log in to the page "exface.core.logs.html" as "Support"
```

Specify multiple roles as a comma-separated list:

```gherkin
Given I log in to the page "exface.core.logs.html" as "Support, Debugger"
```

For technical roles, you can use the full alias:

```gherkin
Given I log in to the page "exface.core.logs.html" as "exface.Core.SUPERUSER"
```

### 3.3 Log in with a locale

```gherkin
Given I log in to the page "exface.core.logs.html" as "Support" with locale "de_DE"
```

Examples of locales:

- `de_DE`
- `en_US`

### Recommended usage

- Login normally goes at the beginning of the scenario.
- For authorization tests, explicitly specify the relevant business role.
- For tests involving language or formatting, also set the locale.

---

## 4. Open pages and run smoke tests

### 4.1 Open another page

When a login session already exists:

```gherkin
When I visit page "my.app.orders"
Then I should see the page
```

`Then I should see the page` checks that the page did not load blank.

### 4.2 Check multiple pages

```gherkin
When I visit the following pages:
  | url                   |
  | my.app.orders.html    |
  | my.app.customers.html |
Then all pages should load successfully
```

This combination is suitable for smoke and regression tests across multiple pages.

---

## 5. Focus principle: select a table or widget first

Many steps do not work globally; they operate on the currently focused widget. Therefore, first specify which element subsequent steps should apply to.

### 5.1 Select a table by position

```gherkin
When I look at table 1
```

For multiple tables:

```gherkin
When I look at table 2
```

Counting starts at `1` and follows the order of the tables on the page.

### 5.2 Select a widget by type

```gherkin
When I look at the first "DataTable"
```

Or select a particular occurrence:

```gherkin
When I look at "Form" no. 2
```

### Why focus matters

After focusing a widget, you can safely use steps such as:

- Set or check filters
- Check columns
- Select table rows
- Use buttons in the table context
- Check content within the table
- Run automatic widget checks

### Negative example

```gherkin
# Incorrect or ambiguous: no table has been focused.
When I enter "Berlin" in filter "City"
```

### Correct example

```gherkin
When I look at table 1
And I enter "Berlin" in filter "City"
```

---

## 6. Find data using filters

### 6.1 Check existing filters

```gherkin
When I look at table 1
Then it has filters: "Name, Created on, Status"
```

You can also check filters that should not be visible:

```gherkin
When I look at table 1
Then I do not see filters "Internal note, Cost center"
```

### 6.2 Check filter order

```gherkin
When I look at table 1
Then the filters are displayed in the following order "Name, City, Status"
```

This step checks the relative order of the specified filters.

### 6.3 Set a filter value

```gherkin
When I look at table 1
And I enter "Berlin" in filter "City"
```

According to the internal step description, the step supports simple text input and special filters such as dropdowns or ComboBoxes. For selection filters, the matching entry is selected.

### 6.4 Run the search

After setting a filter, you would normally click the search or apply button:

```gherkin
And I click button "Suchen"
```

The button text must match the visible label in the application, for example `Search`, `Suchen`, or `Apply`.

### 6.5 Check the result

If all visible rows should contain the filter value:

```gherkin
Then I see "Berlin" in column "City"
```

If at least one row should contain the value:

```gherkin
Then The column "Status" contains value "Open"
```

If the value should appear anywhere in the focused table:

```gherkin
Then the DataTable contains "Berlin"
```

To check only whether data is present:

```gherkin
Then I see at least one data item
```

### Recommended filter pattern

```gherkin
When I look at table 1
And I enter "Berlin" in filter "City"
And I click button "Search"
Then I see "Berlin" in column "City"
```

### Multiple filters

```gherkin
When I look at table 1
And I enter "Berlin" in filter "City"
And I enter "Open" in filter "Status"
And I click button "Search"
Then I see "Berlin" in column "City"
And I see "Open" in column "Status"
```

---

## 7. Check tables and columns

### 7.1 Expected columns

```gherkin
When I look at table 1
Then it has a column "Name"
And it has columns "Name, City, Status"
```

### 7.2 Unexpected columns

```gherkin
When I look at table 1
Then I do not see columns "Salary, Bonus"
```

This is especially useful for role and authorization tests.

### 7.3 Column order

```gherkin
When I look at table 1
Then the columns are displayed in the following order "Name, City, Created on"
```

### 7.4 Select a row

```gherkin
When I look at table 1
And I select table row 1
```

A row is normally selected before a row-specific action such as `Edit` or `Delete`.

```gherkin
When I look at table 1
And I select table row 1
And I click button "Edit"
```

---

## 8. Use buttons and menus

### 8.1 Standard button

```gherkin
When I click button "Save"
```

The step first searches within the current focus and, if necessary, across the whole page.

### 8.2 Button on a specific table

```gherkin
When I click button "Export" on the 2 table
```

Use this variant when multiple tables have buttons with the same label.

### 8.3 Menu button

```gherkin
When I look at table 1
And I click button "Print" in button menu "More actions"
```

### 8.4 Overflow menu

Direct access:

```gherkin
When I look at table 1
And I click "Export" in the overflow menu
```

For a specific table:

```gherkin
When I click "Delete" in the overflow menu on 2 table
```

Alternatively, open the overflow menu first:

```gherkin
When I look at table 1
Then I click the overflow button
```

### 8.5 Check button visibility

```gherkin
Then I see button "Save"
Then I see buttons "Save, Cancel, Delete"
```

Buttons that should not be visible:

```gherkin
Then I do not see the button "Delete"
Then I do not see the buttons "Delete, Approve"
Then I do not see the button "Delete" on the 1 table
```

---

## 9. Tabs and tiles

### Open a tab

```gherkin
When I click tab "Addresses"
```

### Check tabs

```gherkin
Then I see tab "General"
Then I see tabs "General, Addresses, History"
```

### Check tiles

At least the specified tiles must be visible:

```gherkin
Then I see tiles "Orders, Customers, Reports"
```

Only the specified tiles may be visible:

```gherkin
Then I only see tiles "Orders, Customers"
```

The second variant is suitable for precise authorization tests.

---

## 10. Fill in forms

### 10.1 Single field

```gherkin
When I type "John" into "First name"
```

### 10.2 Multiple fields

```gherkin
Then I fill the following fields:
  | widget_name | value         |
  | First name  | John          |
  | Last name   | Doe           |
  | E-mail      | john@doe.test |
```

The table must contain the columns `widget_name` and `value`. Fields are found by their visible labels.

---

## 11. Edit a DataSpreadsheet

### Fill in a specific row

```gherkin
When I fill the row 2 of data spreadsheet with:
  | Column   | Value    |
  | Name     | Widget A |
  | Quantity | 10       |
```

### Fill in the last row

```gherkin
When I fill the last row of data spreadsheet with:
  | Column | Value    |
  | Name   | Widget B |
```

### Check a read-only column

```gherkin
Then the column "ID" in data spreadsheet should be disabled
```

The table used for filling in values must contain the columns `Column` and `Value`.

---

## 12. Automatic widget checks

### Full automatic check

```gherkin
Given I log in to the page "my.app.orders.html" as "Support"
When I look at table 1
Then It works as expected
```

### Check filters only

```gherkin
When I look at table 1
Then The filters work as expected
```

### Check buttons only

```gherkin
When I look at table 1
Then The buttons work as expected
```

### Guided check

```gherkin
When I look at table 1
Then It works as shown below
  | Column Caption | Filter Caption | Button Caption |
  | Order No.      | Customer      | New            |
  | Customer       | Status        | Edit           |
```

Use the guided check when you want to explicitly specify the expected columns, filters, and buttons.

---

## 13. Check the number of widgets

Across the whole page:

```gherkin
Then I see 1 widget of type "DataTable"
Then I see 3 widgets of type "Input"
Then I see 1 widget of type "DataTable" with "Caption of the Datatable"
```

Within the currently focused container:

```gherkin
When I click button "Details"
Then I see 1 widget of type "Dialog"
Then it has 2 widgets of type "Input"
```

---

## 16. Check file exports

```gherkin
When I click button "Export to Excel"
Then an XLSX file should be downloaded
```

The check step expects an actual, non-empty `.xlsx` file to have been downloaded.

---

## 17. Recommended complete scenarios

### 17.1 Filter and result test

```gherkin
Scenario: Show open records from Berlin
  Given I log in to the page "my.app.orders.html" as "Support" with locale "de_DE"

  When I look at table 1
  Then it has filters: City, Status
  And it has columns "Order No., City, Status"

  When I enter "Berlin" in filter "City"
  And I enter "Open" in filter "Status"
  And I click button "Search"

  Then I see "Berlin" in column "City"
  And I see "Open" in column "Status"
```

### 17.2 Authorization test

```gherkin
Scenario: User without delete permission does not see the delete action
  Given I log in to the page "my.app.orders.html" as "Viewer" with locale "de_DE"

  When I look at table 1

  Then I do not see the button "Delete" on the 1 table
  And I do not see columns Internal note, Cost center
```

### 17.3 Smoke test for multiple pages

```gherkin
Scenario: Core pages load without errors
  Given I log in to the page "my.app.start.html" as "Support"

  When I visit the following pages:
    | url                   |
    | my.app.orders.html    |
    | my.app.customers.html |
    | my.app.reports.html   |

  Then all pages should load successfully
```

### 17.4 Export test

```gherkin
Scenario: Export table contents to an Excel file
  Given I log in to the page "my.app.orders.html" as "Support"

  When I look at table 1
  And I click button "Export to Excel"

  Then an XLSX file should be downloaded
```

---

## 17. Writing and quality rules

1. Describe only one clearly identifiable business goal per scenario.
2. Always focus the table or widget before steps involving filters, columns, or rows.
3. Load prepared test data immediately after login.
4. Use visible UI labels exactly as they appear.
5. Specify the role and locale explicitly when they are relevant to the expected behavior.
6. Use `And` only when the logical context of the previous keyword is maintained.
7. Avoid ambiguous global actions when a table-specific variant is available.
8. After every action, add a meaningful business-level check.
9. Do not invent new step text. Document missing steps with `# TODO`.
---

## 18. Quick reference: which step typically follows which?

| Starting step | Typical next step | Purpose |
|---|---|---|
| `Given I log in ...` | `And test data ... is loaded` | Establish a reproducible data set |
| `Given I log in ...` | `Then I should see the page` | Perform a basic page-load check |
| `When I look at table 1` | `Then it has filters: ...` | Check that filters are available |
| `When I look at table 1` | `And I enter ... in filter ...` | Set a filter value |
| `I enter ... in filter ...` | `And I click button "Search"` | Apply the search |
| `I click button "Search"` | `Then I see ... in column ...` | Validate the filtered result |
| `When I look at table 1` | `And I select table row 1` | Prepare a row-specific action |
| `I select table row 1` | `And I click button "Edit"` | Perform an action on the selected row |
| `When I click button "Export to Excel"` | `Then an XLSX file should be downloaded` | Validate the export |
| `When I visit the following pages:` | `Then all pages should load successfully` | Complete a smoke test |
| `When I click tab "..."` | `Then it has ... widget ...` | Check the contents of the opened tab |

---
