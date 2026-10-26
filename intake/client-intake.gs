/**
 * Bike Sense client intake.
 *
 * Paste this into a new Apps Script project named "Bike Sense Client Intake".
 * The shared secret is a script property, not this file.
 *
 * Project Settings → Script properties:
 *   INTAKE_SECRET  same value as BS_STOCK_INTAKE_SECRET in wp-config.php
 *                  (at least 32 characters; the placeholder is refused)
 *   INTAKE_URL     optional. Default is the stock intake route below.
 *
 * Run installIntakeTrigger once, or add an hourly time-driven trigger
 * for processClientIntake. Gmail label: Client Intake.
 * A thread is marked read only after the shop returns 2xx.
 */
function processClientIntake() {
  var props = PropertiesService.getScriptProperties();
  var secret = String(props.getProperty('INTAKE_SECRET') || '').trim();
  var url = props.getProperty('INTAKE_URL') || 'https://stock.bikesense.co.za/wp-json/bikesense-stock/v1/intake';

  if (secret.length < 32 || secret === 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING') {
    Logger.log('Set script property INTAKE_SECRET to the same long random string as BS_STOCK_INTAKE_SECRET.');
    return;
  }

  var threads = GmailApp.search('label:"Client Intake" is:unread');

  threads.forEach(function(thread) {
    var messages = thread.getMessages();
    if (!messages || !messages.length) {
      return;
    }

    var message = messages[0];
    var body = message.getPlainBody();
    var client = {
      name: intakeField(body, /Name:\s*(.*)/i),
      phone: intakeField(body, /Phone:\s*(\+?\d[\d\s-]*)/i).replace(/\s+/g, ''),
      email: intakeField(body, /Email:\s*([^\s]+)/i),
      bike: {
        make: intakeField(body, /Make:\s*(.*)/i),
        model: intakeField(body, /Model:\s*(.*)/i),
        year: intakeField(body, /Year:\s*(.*)/i),
        vin: intakeField(body, /VIN:\s*(.*)/i)
      },
      reason: intakeField(body, /Reason:\s*(.*)/i),
      message_id: message.getId()
    };

    if (!client.name || !client.phone) {
      Logger.log('Skipped – missing name or phone: ' + message.getSubject());
      return;
    }

    var options = {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify({ client: client }),
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

/**
 * One hourly trigger. Safe to run again.
 */
function installIntakeTrigger() {
  var existing = ScriptApp.getProjectTriggers();
  for (var i = 0; i < existing.length; i++) {
    if (existing[i].getHandlerFunction() === 'processClientIntake') {
      Logger.log('Hourly trigger already exists.');
      return;
    }
  }

  ScriptApp.newTrigger('processClientIntake')
    .timeBased()
    .everyHours(1)
    .create();
  Logger.log('Hourly trigger created for processClientIntake.');
}

/**
 * @param {string} body
 * @param {RegExp} pattern
 * @return {string}
 */
function intakeField(body, pattern) {
  var match = String(body || '').match(pattern);
  return match && match[1] ? String(match[1]).trim() : '';
}
