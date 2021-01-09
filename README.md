# amzn_checker
Grabs Amazon products from a Google Sheets sheet, checks them for availability, and saves the result in the sheet.

## Installation
1. You will need a Google Cloud project. You can create one at https://console.developers.google.com/projectcreate. 
    1. Make sure the project has the Sheets API enabled at https://console.developers.google.com/apis/library.
    2. Crate a service account within this project. You can create a service account at https://console.developers.google.com/identity/serviceaccounts. 
    3. Create a key pair for that service account and download it as JSON file.
2. Place the downloaded JSON key file in `gsa-credentials.json`.
3. Edit the `config.json` file and specify the `sheet_id` you desire to use. The sheet ID is the long token you see in the URL of a Google Sheets.
4. Make sure the Google Service Account email address has access to the sheet by adding it as a collaborator. Your GSA email address can be found in `gsa-credentials.json` under `client_email` Alternatively, you can allow editing for everyone with access to the link (not recommended).

## Example
An example spreadsheet that will work with the default config can be found at https://docs.google.com/spreadsheets/d/19dm4B0QJsSSe7h5bwAsXG2E-Sx3CFyQd3_fnhNivu9k/edit#gid=0. If you want to use it, copy it to your directory and add your GSA email to it as collaborator or allow editing under link sharing.

## Dependencies
None.