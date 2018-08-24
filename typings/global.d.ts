// nuclear option to fix all module import type issues:
// declare module '*';

interface Window {
  wp: any;
  web3: any;
  _wpGutenbergPost: any;
  civilNamespace: {
    newsroomAddress: string;
    newsroomTxHash: string;
    wpSiteUrl: string;
    wpAdminUrl: string;
  };
  civilImages: {
    metamask_confim_modal: string;
    metamask_logo: string;
  };
}

declare module "refx";
declare module "redux-multi";
declare module "moment"; // included via Gutenberg
declare module "jquery"; // included on all WordPress pages
declare module "*.png";