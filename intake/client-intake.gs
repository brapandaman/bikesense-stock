/**
 * Bike Sense client intake.
 *
 * Paste this project at script.google.com and name it "Bike Sense Client Intake".
 * Do not put the secret in this file.
 *
 * Script properties (Project Settings → Script properties):
 *   INTAKE_SECRET  same long random string as BS_STOCK_INTAKE_SECRET in wp-config.php
 *   INTAKE_URL     optional, defaults to the stock site REST route below
 *
 * Trigger: processClientIntake, time-driven, hour timer, every hour.
 * Gmail label: Client Intake. Leave matching mail unread until this script accepts it.
 *
 * Plain-text body lines: Name, Phone, Email, Make, Model, Year, VIN, Reason.
 */
function processClientIntake() {
  var props = PropertiesService.getScriptProperties();
  var secret = props.getProperty('INTAKE_SECRET');
  var url = props.getProperty('INTAKE_URL') || 'https://stock.bikesense.co.za/wp-json/bikesense-stock/v1/intake';

  if (!secret || secret.length < 32 || secret === 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING') {
    Logger.log('Set INTAKE_SECRET in Script properties. It must match wp-config.php and is not stored in this file.');
    return;
  }

  var threads = GmailApp.search('label:"Client Intake" is:unread');

  threads.forEach(function(thread) {
    var message = thread.getMessages()[0];
    var body = message.getPlainBody();

    var client = {
      name: (body.match(/Name:\s*(.*)/i) || [])[1] || '',
      phone: (body.match(/Phone:\s*(\+?\d[\d\s-]*)/i) || [])[1] || '',
      email: (body.match(/Email:\s*([^\s]+)/i) || [])[1] || '',
      bike: {
        make: (body.match(/Make:\s*(.*)/i) || [])[1] || '',
        model: (body.match(/Model:\s*(.*)/i) || [])[1] || '',
        year: (body.match(/Year:\s*(.*)/i) || [])[1] || '',
        vin: (body.match(/VIN:\s*(.*)/i) || [])[1] || ''
      },
      reason: (body.match(/Reason:\s*(.*)/i) || [])[1] || ''
    };

    client.name = client.name.trim();
    client.phone = client.phone.replace(/\s+/g, '');

    if (!client.name || !client.phone) {
      Logger.log('Skipped – missing name or phone: ' + message.getSubject());
      return;
    }

    var payload = JSON.stringify({
      client: client,
      message_id: message.getId()
    });
    var options = {
      method: 'post',
      contentType: 'application/json',
      payload: payload,
      headers: { 'X-Intake-Secret': secret },
      muteHttpExceptions: true
    };

    try {
      var response = UrlFetchApp.fetch(url, options);
      var code = response.getResponseCode();
      if (code >= 200 && code < 300) {
        thread.markRead();
        Logger.log('Logged: ' + client.name + ' – ' + client.bike.make + ' ' + client.bike.model);
      } else {
        Logger.log('Failed (' + code + '): ' + response.getContentText());
      }
    } catch (e) {
      Logger.log('Fetch error: ' + e);
    }
  });
}
