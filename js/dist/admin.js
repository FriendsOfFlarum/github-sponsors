module.exports =
/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, { enumerable: true, get: getter });
/******/ 		}
/******/ 	};
/******/
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 			Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 		}
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
/******/ 	};
/******/
/******/ 	// create a fake namespace object
/******/ 	// mode & 1: value is a module id, require it
/******/ 	// mode & 2: merge all properties of value into the ns
/******/ 	// mode & 4: return value when already ns object
/******/ 	// mode & 8|1: behave like require
/******/ 	__webpack_require__.t = function(value, mode) {
/******/ 		if(mode & 1) value = __webpack_require__(value);
/******/ 		if(mode & 8) return value;
/******/ 		if((mode & 4) && typeof value === 'object' && value && value.__esModule) return value;
/******/ 		var ns = Object.create(null);
/******/ 		__webpack_require__.r(ns);
/******/ 		Object.defineProperty(ns, 'default', { enumerable: true, value: value });
/******/ 		if(mode & 2 && typeof value != 'string') for(var key in value) __webpack_require__.d(ns, key, function(key) { return value[key]; }.bind(null, key));
/******/ 		return ns;
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "";
/******/
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = "./admin.js");
/******/ })
/************************************************************************/
/******/ ({

/***/ "./admin.js":
/*!******************!*\
  !*** ./admin.js ***!
  \******************/
/*! no exports provided */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _src_admin__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! ./src/admin */ "./src/admin/index.js");
/* empty/unused harmony star reexport */

/***/ }),

/***/ "./src/admin/index.js":
/*!****************************!*\
  !*** ./src/admin/index.js ***!
  \****************************/
/*! no exports provided */
/***/ (function(module, __webpack_exports__, __webpack_require__) {

"use strict";
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _fof_components__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @fof-components */ "@fof-components");
/* harmony import */ var _fof_components__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_fof_components__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var flarum_components_Link__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! flarum/components/Link */ "flarum/components/Link");
/* harmony import */ var flarum_components_Link__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(flarum_components_Link__WEBPACK_IMPORTED_MODULE_1__);


var SettingsModal = _fof_components__WEBPACK_IMPORTED_MODULE_0__["settings"].SettingsModal,
    _settings$items = _fof_components__WEBPACK_IMPORTED_MODULE_0__["settings"].items,
    StringItem = _settings$items.StringItem,
    SelectItem = _settings$items.SelectItem;

var getOptions = function getOptions() {
  return ['user', 'organization'].reduce(function (o, type) {
    o[type] = app.translator.trans("fof-github-sponsors.admin.account_types." + type);
    return o;
  }, {});
};

app.initializers.add('fof/github-sponsors', function () {
  app.extensionSettings['fof-github-sponsors'] = function () {
    return app.modal.show(SettingsModal, {
      title: app.translator.trans('fof-github-sponsors.admin.settings.title'),
      size: 'medium',
      items: function items(s) {
        return [m("p", null, app.translator.trans('fof-github-sponsors.admin.settings.desc', {
          a: m(flarum_components_Link__WEBPACK_IMPORTED_MODULE_1___default.a, {
            href: "https://github.com/settings/tokens",
            target: "_blank"
          })
        })), m(StringItem, {
          setting: s,
          name: "fof-github-sponsors.api_token",
          required: true,
          type: "password"
        }, app.translator.trans('fof-github-sponsors.admin.settings.api_token_label')), [m("label", null, app.translator.trans('fof-github-sponsors.admin.settings.account_type_label')), m(SelectItem, {
          setting: s,
          name: "fof-github-sponsors.account_type",
          options: getOptions(),
          required: true
        })], m(StringItem, {
          setting: s,
          name: "fof-github-sponsors.login",
          required: true
        }, app.translator.trans('fof-github-sponsors.admin.settings.login_label')), m("div", {
          className: "Form-group"
        }, m("label", null, app.translator.trans('fof-github-sponsors.admin.settings.group_label')), SelectItem.component({
          options: app.store.all('groups').reduce(function (o, g) {
            o[g.id()] = g.nameSingular();
            return o;
          }, {}),
          name: 'fof-github-sponsors.group_id',
          setting: s,
          required: true
        }))];
      }
    });
  };
});

/***/ }),

/***/ "@fof-components":
/*!******************************************************!*\
  !*** external "flarum.extensions['fof-components']" ***!
  \******************************************************/
/*! no static exports found */
/***/ (function(module, exports) {

module.exports = flarum.extensions['fof-components'];

/***/ }),

/***/ "flarum/components/Link":
/*!********************************************************!*\
  !*** external "flarum.core.compat['components/Link']" ***!
  \********************************************************/
/*! no static exports found */
/***/ (function(module, exports) {

module.exports = flarum.core.compat['components/Link'];

/***/ })

/******/ });
//# sourceMappingURL=admin.js.map