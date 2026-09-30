const path = require('node:path');

module.exports = {
  testDir: path.join(__dirname, 'tests', 'Browser'),
  reporter: 'list',
};
