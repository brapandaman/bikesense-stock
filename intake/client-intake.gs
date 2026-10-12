/**
 * Bike Sense client intake.
 *
 * Setup (script.google.com → New project → name it "Bike Sense Client Intake"):
 * 1. In Stock → Settings, create an intake secret and paste it below.
 * 2. Save this file as the project script.
 * 3. Triggers → Add trigger → processClientIntake → Time-driven → Hour timer → Every hour.
 * 4. Gmail label: Client Intake. Leave matching mail unread until this script logs it.
 *
 * The secret must match the one stored by the stock site. Do not commit a real secret.
 */
function processClientIntake() {
  var secret = ''; // Paste the intake secret from Stock → Settings.
  var url = 'https://stock.bikesense.co.za/wp-json/bikesense-stock/v1/intake';

  if (!secret) {
    Logger.log('Set the intake secret from Bike Sense Stock → Settings before running.');
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
      reason: (body.match(/Reason:\s*(.*)/i) || [])[1] || '',
      message_id: message.getId()
    };

    client.name = client.name.trim();
    client.phone = client.phone.replace(/\s+/g, '');

    if (!client.name || !client.phone) {
      Logger.log('Skipped – missing name or phone: ' + message.getSubject());
      return;
    }

    var payload = JSON.stringify({ client: client });
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
